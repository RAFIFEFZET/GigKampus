<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\PostgresTestCase;

class PostgresConcurrencyTest extends PostgresTestCase
{
    public function test_cross_table_email_uniqueness_serializes_normalized_account_writes(): void
    {
        $email = 'phase2.'.strtolower((string) Str::ulid()).'@apps.ipb.ac.id';
        $user = $this->copyRow($this->row('users', ['is_verified' => false]), ['email' => $email]);
        $admin = $this->copyRow($this->row('admins'), ['email' => "\t".strtoupper($email).' ']);

        $this->assertCompetingInserts('users', $user, 'admins', $admin);
    }

    public function test_waiting_email_trigger_rechecks_the_other_connection_after_commit(): void
    {
        // Run the same trigger in a disposable namespace so the committing writer leaves no demo account behind.
        $schema = 'email_race_'.bin2hex(random_bytes(8));
        config(['database.connections.email_writer' => DB::connection()->getConfig()]);
        $writer = DB::connection('email_writer');
        $writer->statement("CREATE SCHEMA {$schema}");
        $peer = null;

        try {
            $writer->unprepared(<<<SQL
                CREATE TABLE {$schema}.users (email VARCHAR(254) NOT NULL UNIQUE);
                CREATE TABLE {$schema}.admins (email VARCHAR(254) NOT NULL UNIQUE);
                CREATE TRIGGER users_global_email BEFORE INSERT ON {$schema}.users
                    FOR EACH ROW EXECUTE FUNCTION public.gigkampus_check_global_email();
                CREATE TRIGGER admins_global_email BEFORE INSERT ON {$schema}.admins
                    FOR EACH ROW EXECUTE FUNCTION public.gigkampus_check_global_email();
                SET search_path TO {$schema}, public;
                SQL);

            $email = $schema.'@apps.ipb.ac.id';
            $writer->beginTransaction();
            $writer->table('users')->insert(['email' => $email]);

            $peerCode = <<<'PHP'
                require 'vendor/autoload.php';
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                [, $schema, $email] = $argv;
                $connection = $app['db']->connection();
                $connection->select(
                    "SELECT set_config('search_path', ?, false), set_config('application_name', ?, false)",
                    [$schema.', public', $schema],
                );
                try {
                    $connection->table('admins')->insert(['email' => " ".strtoupper($email)."\t"]);
                    echo 'accepted';
                } catch (Illuminate\Database\QueryException $exception) {
                    echo $exception->errorInfo[0];
                }
                PHP;
            $peer = new Process([PHP_BINARY, '-r', $peerCode, $schema, $email], base_path(), timeout: 10);
            $peer->start();

            $this->assertPeerWaitsForEmailLock($schema, $peer);
            $writer->commit();

            self::assertSame(0, $peer->wait(), $peer->getErrorOutput());
            self::assertSame('23505', $peer->getOutput());
            self::assertSame(0, $writer->table('admins')->count());
        } finally {
            $peer?->stop();
            if ($writer->transactionLevel() !== 0) {
                $writer->rollBack();
            }
            $writer->statement("DROP SCHEMA {$schema} CASCADE");
            DB::purge('email_writer');
        }
    }

    public function test_two_sellers_cannot_create_two_accepted_offers_for_one_request(): void
    {
        $request = DB::table('service_requests')
            ->where('status', 'open')
            ->whereNotIn('id', DB::table('price_offers')->select('service_request_id'))
            ->first();
        self::assertNotNull($request);

        $sellers = DB::table('users')->where('is_verified', true)->where('is_suspended', false)
            ->where('id', '<>', $request->buyer_id)->orderBy('id')->limit(2)->pluck('id');
        self::assertCount(2, $sellers);
        $attributes = $this->copyRow($this->row('price_offers', ['status' => 'accepted']), [
            'service_request_id' => $request->id,
            'offered_price' => $request->budget_max,
        ]);

        $this->assertCompetingInserts(
            'price_offers', [...$attributes, 'seller_id' => $sellers[0]],
            'price_offers', [...$attributes, 'seller_id' => $sellers[1]],
        );
    }

    public function test_two_checkouts_cannot_create_two_orders_for_one_offer(): void
    {
        $offer = DB::table('price_offers')
            ->whereNotIn('id', DB::table('orders')->whereNotNull('price_offer_id')->select('price_offer_id'))
            ->first();
        self::assertNotNull($offer);
        $request = $this->row('service_requests', ['id' => $offer->service_request_id]);
        $attributes = $this->copyRow($this->row('orders', ['transaction_type' => 'custom_request']), [
            'price_offer_id' => $offer->id,
            'buyer_id' => $request->buyer_id,
            'seller_id' => $offer->seller_id,
        ]);

        $this->assertCompetingInserts(
            'orders', [...$attributes, 'creation_key' => (string) Str::uuid()],
            'orders', [...$attributes, 'creation_key' => (string) Str::uuid()],
        );
    }

    public function test_repeated_direct_checkout_cannot_reuse_the_same_idempotency_key(): void
    {
        $order = $this->row('orders', ['transaction_type' => 'direct_gig', 'status' => 'awaiting_payment']);
        $attributes = $this->copyRow($order, ['creation_key' => (string) Str::uuid()]);

        $this->assertCompetingInserts('orders', $attributes, 'orders', $attributes);
    }

    public function test_competing_requests_create_at_most_one_unfinished_revision(): void
    {
        $order = DB::table('orders')->where('status', 'delivered')->where('max_revisions_snapshot', '>=', 2)
            ->whereNotIn('id', DB::table('revisions')->select('order_id'))->first();
        self::assertNotNull($order);
        $attributes = $this->copyRow($this->row('revisions', ['status' => 'requested']), [
            'order_id' => $order->id,
            'revision_no' => 1,
        ]);

        $this->assertCompetingInserts('revisions', $attributes, 'revisions', [...$attributes, 'revision_no' => 2]);
    }

    public function test_order_complaint_uniqueness_applies_across_both_participants(): void
    {
        $order = $this->row('orders', ['status' => 'awaiting_validation']);
        $attributes = $this->copyRow($this->row('reports', ['report_type' => 'order_complaint', 'status' => 'pending']), [
            'order_id' => $order->id,
            'order_status_before_dispute' => $order->status,
            'order_stage_version' => $order->stage_version,
        ]);

        $this->assertCompetingInserts(
            'reports', [...$attributes, 'reporter_id' => $order->buyer_id],
            'reports', [...$attributes, 'reporter_id' => $order->seller_id],
        );
    }

    public function test_competing_submissions_create_at_most_one_review(): void
    {
        $order = DB::table('orders')->where('status', 'completed')
            ->whereNotIn('id', DB::table('reviews')->select('order_id'))->first();
        self::assertNotNull($order);

        $attributes = $this->copyRow($this->row('reviews', ['is_hidden' => false]), [
            'order_id' => $order->id,
            'reviewer_id' => $order->buyer_id,
            'seller_id' => $order->seller_id,
        ]);

        $this->assertCompetingInserts('reviews', $attributes, 'reviews', [...$attributes, 'rating' => 4]);
    }

    private function assertCompetingInserts(string $firstTable, array $first, string $secondTable, array $second): void
    {
        $primary = DB::connection();
        config(['database.connections.integrity_peer' => $primary->getConfig()]);
        $peer = DB::connection('integrity_peer');

        try {
            self::assertNotSame(
                $primary->selectOne('SELECT pg_backend_pid() AS pid')->pid,
                $peer->selectOne('SELECT pg_backend_pid() AS pid')->pid,
            );
            self::assertTrue($primary->table($firstTable)->insert($first));

            $this->assertSqlState('55P03', function (Connection $connection) use ($secondTable, $second) {
                $connection->statement("SET LOCAL lock_timeout = '250ms'");
                $connection->table($secondTable)->insert($second);
            }, $peer);
            $this->assertSqlState('23505', static fn (Connection $connection) => $connection->table($secondTable)->insert($second));
        } finally {
            DB::purge('integrity_peer');
        }
    }

    private function assertPeerWaitsForEmailLock(string $name, Process $peer): void
    {
        $deadline = microtime(true) + 5;
        while ($peer->isRunning() && microtime(true) < $deadline) {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne(<<<'SQL'
                SELECT EXISTS (
                    SELECT 1 FROM pg_stat_activity
                    WHERE application_name = ? AND wait_event = 'advisory'
                ) AS waiting
                SQL, [$name])->waiting;
            if ($waiting) {
                self::assertTrue($waiting);

                return;
            }
            usleep(20_000);
        }

        self::fail('The competing connection did not wait for the email lock. '.$peer->getOutput().$peer->getErrorOutput());
    }
}
