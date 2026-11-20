<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Models\Admin;
use App\Models\Order;
use App\Services\ReportService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReportConcurrencyTest extends TestCase
{
    private const INPUT = [
        'category' => 'transaction_issue',
        'description' => 'Bukti pembayaran demo perlu diperiksa kembali oleh Admin.',
    ];

    private string $schema;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('pgsql', DB::connection()->getDriverName());
        $this->schema = 'report_race_'.bin2hex(random_bytes(8));
        DB::statement("CREATE SCHEMA {$this->schema}");
        config(['database.connections.pgsql.search_path' => $this->schema]);
        DB::purge();
        self::assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());

        $source = DB::table('public.orders')->where('status', OrderStatus::AwaitingValidation->value)
            ->where('transaction_type', 'direct_gig')->orderBy('id')->first();
        self::assertNotNull($source, 'Seed the test database before running report concurrency tests.');

        DB::statement('INSERT INTO banks SELECT * FROM public.banks');
        DB::statement('INSERT INTO categories SELECT * FROM public.categories');
        DB::statement('INSERT INTO users SELECT * FROM public.users WHERE id IN (?, ?)', [$source->buyer_id, $source->seller_id]);
        DB::statement('INSERT INTO gigs SELECT * FROM public.gigs WHERE id = ?', [$source->gig_id]);
        DB::statement('INSERT INTO orders SELECT * FROM public.orders WHERE id = ?', [$source->id]);
        DB::statement('INSERT INTO payments SELECT * FROM public.payments WHERE order_id = ?', [$source->id]);
        DB::statement('INSERT INTO payment_proofs SELECT * FROM public.payment_proofs WHERE payment_id IN (SELECT id FROM payments)');
        DB::statement('INSERT INTO admins SELECT * FROM public.admins ORDER BY id LIMIT 1');

        $this->order = Order::query()->findOrFail($source->id);
    }

    protected function tearDown(): void
    {
        if (isset($this->schema)) {
            DB::connection()->rollBack(0);
            DB::statement("DROP SCHEMA {$this->schema} CASCADE");
        }

        parent::tearDown();
    }

    public function test_two_admins_competing_for_a_case_leave_one_handler(): void
    {
        $service = app(ReportService::class);
        $report = $service->submit($this->order->buyer, ReportType::OrderComplaint, $this->order->id, self::INPUT);
        $winner = Admin::query()->sole();
        $other = Admin::query()->forceCreate([
            'id' => $winner->id + 1,
            'name' => 'Admin Uji Kedua',
            'email' => 'second.admin@gigkampus.test',
            'password_hash' => $winner->getAuthPassword(),
        ]);

        DB::beginTransaction();
        self::assertSame($winner->id, $service->claim($winner, $report)->handled_by_admin_id);
        $this->commitWhenPeerWaits('claim', $other->id, $report->id);

        self::assertSame($winner->id, $report->refresh()->handled_by_admin_id);
        self::assertSame(ReportStatus::Pending, $report->status);
        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseCount('notifications', 2);
        self::assertSame(1, $report->auditLogs()->where('action', 'report.claimed')->count());
    }

    public function test_two_order_participants_competing_to_open_a_complaint_create_one_dispute(): void
    {
        $sourceStage = $this->order->stage_version;
        $buyer = $this->order->buyer;
        $seller = $this->order->seller;

        DB::beginTransaction();
        $report = app(ReportService::class)->submit($buyer, ReportType::OrderComplaint, $this->order->id, self::INPUT);
        $this->commitWhenPeerWaits('complaint', $seller->id, $this->order->id);

        self::assertSame(1, $this->order->reports()->where('status', ReportStatus::Pending)->count());
        self::assertSame($buyer->id, $report->refresh()->reporter_id);
        self::assertSame(OrderStatus::AwaitingValidation, $report->order_status_before_dispute);
        self::assertSame($sourceStage, $report->order_stage_version);
        self::assertSame(OrderStatus::Disputed, $this->order->refresh()->status);
        self::assertSame($sourceStage, $this->order->stage_version);
        self::assertSame(1, $report->auditLogs()->where('action', 'order.disputed')->count());
        $this->assertDatabaseCount('notifications', 2);
    }

    private function commitWhenPeerWaits(string $action, int $actor, int $target): void
    {
        $writer = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $code = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            [, $schema, $action, $actor, $target, $input] = $argv;
            $app['db']->connection()->select(
                "SELECT set_config('search_path', ?, false), set_config('application_name', ?, false)",
                [$schema, $schema],
            );
            $service = $app->make(App\Services\ReportService::class);
            try {
                if ($action === 'claim') {
                    $service->claim(App\Models\Admin::findOrFail($actor), App\Models\Report::findOrFail($target));
                } else {
                    $service->submit(
                        App\Models\User::findOrFail($actor),
                        App\Enums\ReportType::OrderComplaint,
                        (int) $target,
                        json_decode($input, true, flags: JSON_THROW_ON_ERROR),
                    );
                }
                echo 'accepted';
            } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
                echo $exception->getStatusCode();
            }
            PHP;

        $peer = new Process([
            PHP_BINARY, '-r', $code, $this->schema, $action, (string) $actor, (string) $target,
            json_encode(self::INPUT, JSON_THROW_ON_ERROR),
        ], base_path(), timeout: 25);
        $peer->start();

        try {
            $deadline = microtime(true) + 15;
            $waiting = false;
            while ($peer->isRunning() && microtime(true) < $deadline) {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::selectOne(<<<'SQL'
                    SELECT EXISTS (
                        SELECT 1 FROM pg_stat_activity
                        WHERE application_name = ? AND wait_event_type = 'Lock'
                            AND ? = ANY(pg_blocking_pids(pid))
                    ) AS waiting
                    SQL, [$this->schema, $writer])->waiting;
                if ($waiting) {
                    break;
                }
                usleep(20_000);
            }

            self::assertTrue($waiting, 'The competing report operation did not wait for the first writer. '.$peer->getErrorOutput());
            DB::commit();
            self::assertSame(0, $peer->wait(), $peer->getErrorOutput());
            self::assertSame('409', $peer->getOutput());
        } finally {
            $peer->stop();
        }
    }
}
