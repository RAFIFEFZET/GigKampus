<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PriceOfferStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Category;
use App\Models\Gig;
use App\Models\Order;
use App\Models\PriceOffer;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Orders\DirectCheckoutService;
use App\Services\Orders\OfferAcceptanceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CheckoutConcurrencyTest extends TestCase
{
    private string $schema;

    private const BRIEF = 'Cantumkan jadwal kegiatan kampus pada poster sesuai materi.';

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('pgsql', DB::connection()->getDriverName());
        $this->schema = 'checkout_race_'.bin2hex(random_bytes(8));
        DB::statement("CREATE SCHEMA {$this->schema}");
        config(['database.connections.pgsql.search_path' => $this->schema]);
        DB::purge();
        self::assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());

        DB::statement('INSERT INTO banks SELECT * FROM public.banks');
        DB::statement(<<<'SQL'
            INSERT INTO users SELECT * FROM public.users WHERE email IN (
                'buyer.demo@apps.ipb.ac.id', 'seller.demo@apps.ipb.ac.id', 'seller2.demo@apps.ipb.ac.id'
            )
            SQL);
        self::assertSame(3, User::count());
    }

    protected function tearDown(): void
    {
        if (isset($this->schema)) {
            DB::connection()->rollBack(0);
            DB::statement("DROP SCHEMA {$this->schema} CASCADE");
        }

        parent::tearDown();
    }

    public function test_concurrent_direct_checkout_retry_gets_same_order_from_other_connection(): void
    {
        $buyer = $this->student('buyer');
        $gig = Gig::query()->forceCreate([
            'seller_id' => $this->student('seller')->id,
            'category_id' => Category::create(['name' => 'Kategori Checkout Race'])->id,
            'title' => 'Poster Kegiatan Kampus',
            'description' => 'Pembuatan poster untuk kegiatan mahasiswa di kampus.',
            'base_price' => 150000,
            'delivery_days' => 3,
            'max_revisions' => 2,
        ]);
        $key = (string) Str::uuid();

        DB::beginTransaction();
        $order = app(DirectCheckoutService::class)->checkout($buyer, $gig, $key, 1, PaymentMethod::BankTransfer, self::BRIEF);

        $this->assertPeerResult('gig', $buyer->id, $gig->id, $key, 'order:'.$order->id);

        self::assertSame(1, Order::where('gig_id', $gig->id)->count());
        self::assertSame(1, $order->payment()->count());
        self::assertSame(1, $order->chatRoom()->count());
        self::assertSame(1, $order->notifications()->count());
        self::assertSame(1, $order->auditLogs()->count());
    }

    public function test_concurrent_offer_acceptance_rejects_the_other_checkout_after_commit(): void
    {
        $buyer = $this->student('buyer');
        $request = ServiceRequest::query()->forceCreate([
            'buyer_id' => $buyer->id,
            'category_id' => Category::create(['name' => 'Kategori Checkout Race'])->id,
            'title' => 'Permintaan Poster Kampus',
            'description' => 'Membutuhkan poster kegiatan dengan bahan yang sudah disediakan.',
            'budget_min' => 100000,
            'budget_max' => 200000,
            'deadline_date' => now('Asia/Jakarta')->addDay()->toDateString(),
        ]);
        $winner = $this->offer($request, $this->student('seller'));
        $loser = $this->offer($request, $this->student('seller2'));
        $loserKey = (string) Str::uuid();

        DB::beginTransaction();
        $order = app(OfferAcceptanceService::class)->accept(
            $buyer, $winner, (string) Str::uuid(), 1, 1, PaymentMethod::BankTransfer,
        );

        $this->assertPeerResult('offer', $buyer->id, $loser->id, $loserKey, 'conflict');

        self::assertSame(ServiceRequestStatus::Ordered, $request->refresh()->status);
        self::assertSame(PriceOfferStatus::Accepted, $winner->refresh()->status);
        self::assertSame(PriceOfferStatus::Rejected, $loser->refresh()->status);
        self::assertFalse(Order::where('creation_key', $loserKey)->exists());
        self::assertSame(1, Order::count());
        self::assertSame(1, $order->payment()->count());
        self::assertSame(1, $order->chatRoom()->count());
    }

    private function student(string $key): User
    {
        return User::where('email', $key.'.demo@apps.ipb.ac.id')->sole();
    }

    private function offer(ServiceRequest $request, User $seller): PriceOffer
    {
        return PriceOffer::query()->forceCreate([
            'service_request_id' => $request->id,
            'seller_id' => $seller->id,
            'offered_price' => 150000,
            'max_revisions' => 2,
            'proposal_note' => 'Satu desain utama dan dua revisi sesuai brief permintaan.',
        ]);
    }

    private function assertPeerResult(string $mode, int $buyerId, int $sourceId, string $key, string $expected): void
    {
        $code = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            [, $schema, $mode, $buyer, $source, $key, $brief] = $argv;
            $app['db']->connection()->select(
                "SELECT set_config('search_path', ?, false), set_config('application_name', ?, false)",
                [$schema, $schema],
            );
            $buyer = App\Models\User::findOrFail($buyer);
            $method = App\Enums\PaymentMethod::BankTransfer;
            try {
                $order = $mode === 'gig'
                    ? $app->make(App\Services\Orders\DirectCheckoutService::class)
                        ->checkout($buyer, App\Models\Gig::findOrFail($source), $key, 1, $method, $brief)
                    : $app->make(App\Services\Orders\OfferAcceptanceService::class)
                        ->accept($buyer, App\Models\PriceOffer::findOrFail($source), $key, 1, 1, $method);
                echo 'order:'.$order->id;
            } catch (App\Exceptions\CheckoutConflict) {
                echo 'conflict';
            }
            PHP;
        $peer = new Process(
            [PHP_BINARY, '-r', $code, $this->schema, $mode, (string) $buyerId, (string) $sourceId, $key, self::BRIEF],
            base_path(),
            timeout: 25,
        );
        $peer->start();

        try {
            // Laravel's bootstrap on a Windows bind mount can take several seconds before the peer reaches PostgreSQL.
            $deadline = microtime(true) + 15;
            $waiting = false;
            while ($peer->isRunning() && microtime(true) < $deadline) {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::selectOne(<<<'SQL'
                    SELECT EXISTS (
                        SELECT 1 FROM pg_stat_activity
                        WHERE application_name = ? AND wait_event_type = 'Lock'
                    ) AS waiting
                    SQL, [$this->schema])->waiting;
                if ($waiting) {
                    break;
                }
                usleep(20_000);
            }
            self::assertTrue($waiting, 'Checkout peer did not wait on a transaction lock. '.$peer->getErrorOutput());
            DB::commit();

            self::assertSame(0, $peer->wait(), $peer->getErrorOutput());
            self::assertSame($expected, $peer->getOutput());
        } finally {
            $peer->stop();
        }
    }
}
