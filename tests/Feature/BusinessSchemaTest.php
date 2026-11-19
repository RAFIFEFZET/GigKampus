<?php

namespace Tests\Feature;

use App\Enums\ActorType;
use App\Enums\CancellationStatus;
use App\Enums\ChatMessageType;
use App\Enums\ChatRoomStatus;
use App\Enums\GigStatus;
use App\Enums\KtmVerificationStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProofStatus;
use App\Enums\PaymentStatus;
use App\Enums\PriceOfferStatus;
use App\Enums\RefundStatus;
use App\Enums\ReportCategory;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\RevisionStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\TransactionType;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class BusinessSchemaTest extends PostgresTestCase
{
    private const BUSINESS_COLUMNS = [
        'admins' => 6,
        'users' => 17,
        'categories' => 3,
        'banks' => 2,
        'gigs' => 13,
        'service_requests' => 13,
        'price_offers' => 11,
        'orders' => 32,
        'payments' => 16,
        'payment_proofs' => 12,
        'revisions' => 7,
        'order_deliveries' => 10,
        'reviews' => 11,
        'chat_rooms' => 9,
        'chat_messages' => 13,
        'notifications' => 11,
        'reports' => 23,
        'report_clarifications' => 7,
        'report_clarification_responses' => 10,
        'ktm_verification_requests' => 15,
        'audit_logs' => 13,
    ];

    public function test_schema_contains_the_21_business_tables_and_three_operational_tables(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->get()
            ->groupBy('table_name');

        self::assertEqualsCanonicalizing(
            [...array_keys(self::BUSINESS_COLUMNS), 'migrations', 'jobs', 'failed_jobs'],
            $columns->keys()->all(),
        );

        foreach (self::BUSINESS_COLUMNS as $table => $count) {
            self::assertCount($count, $columns[$table], $table);
            $id = $columns[$table]->firstWhere('column_name', 'id');
            self::assertSame('bigint', $id->data_type, $table);
            self::assertStringStartsWith('nextval(', $id->column_default, $table);
        }

        foreach ([
            'gigs' => ['base_price'],
            'service_requests' => ['budget_min', 'budget_max'],
            'price_offers' => ['offered_price'],
            'orders' => ['total_price'],
            'payments' => ['amount'],
        ] as $table => $moneyColumns) {
            foreach ($moneyColumns as $name) {
                $column = $columns[$table]->firstWhere('column_name', $name);
                self::assertSame('numeric', $column->data_type);
                self::assertSame(12, $column->numeric_precision);
                self::assertSame(0, $column->numeric_scale);
            }
        }

        foreach ([
            ['orders', 'creation_key', 'uuid'],
            ['chat_messages', 'client_message_key', 'uuid'],
            ['orders', 'delivery_deadline_at', 'timestamp with time zone'],
            ['service_requests', 'deadline_date', 'date'],
            ['notifications', 'data', 'jsonb'],
            ['audit_logs', 'before_data', 'jsonb'],
            ['audit_logs', 'after_data', 'jsonb'],
        ] as [$table, $name, $type]) {
            self::assertSame($type, $columns[$table]->firstWhere('column_name', $name)->data_type);
        }

        $foreignKeys = DB::table('information_schema.referential_constraints')
            ->where('constraint_schema', 'public')
            ->get();

        self::assertCount(56, $foreignKeys);
        foreach ($foreignKeys as $key) {
            self::assertSame('RESTRICT', $key->delete_rule, $key->constraint_name);
            self::assertSame('RESTRICT', $key->update_rule, $key->constraint_name);
        }
    }

    public static function enumColumns(): iterable
    {
        yield 'audit actor' => ['audit_logs', 'actor_type', ActorType::class];
        yield 'cancellation' => ['orders', 'cancellation_status', CancellationStatus::class];
        yield 'chat message type' => ['chat_messages', 'message_type', ChatMessageType::class];
        yield 'chat room' => ['chat_rooms', 'status', ChatRoomStatus::class];
        yield 'gig' => ['gigs', 'status', GigStatus::class];
        yield 'KTM' => ['ktm_verification_requests', 'status', KtmVerificationStatus::class];
        yield 'order' => ['orders', 'status', OrderStatus::class];
        yield 'payment method' => ['payments', 'payment_method', PaymentMethod::class];
        yield 'payment proof' => ['payment_proofs', 'seller_review_status', PaymentProofStatus::class];
        yield 'payment' => ['payments', 'status', PaymentStatus::class];
        yield 'price offer' => ['price_offers', 'status', PriceOfferStatus::class];
        yield 'refund' => ['payments', 'refund_status', RefundStatus::class];
        yield 'report category' => ['reports', 'category', ReportCategory::class];
        yield 'report status' => ['reports', 'status', ReportStatus::class];
        yield 'report type' => ['reports', 'report_type', ReportType::class];
        yield 'revision' => ['revisions', 'status', RevisionStatus::class];
        yield 'service request' => ['service_requests', 'status', ServiceRequestStatus::class];
        yield 'transaction type' => ['orders', 'transaction_type', TransactionType::class];
    }

    /** @param class-string<BackedEnum> $enum */
    #[DataProvider('enumColumns')]
    public function test_backed_enum_values_match_the_postgresql_check(string $table, string $column, string $enum): void
    {
        $checks = DB::select(<<<'SQL'
            SELECT pg_get_constraintdef(c.oid) AS definition
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            JOIN pg_attribute a ON a.attrelid = t.oid AND c.conkey = ARRAY[a.attnum]
            WHERE c.contype = 'c'
              AND n.nspname = 'public'
              AND t.relname = ?
              AND a.attname = ?
            SQL, [$table, $column]);

        $enumValues = array_column($enum::cases(), 'value');
        foreach ($checks as $check) {
            preg_match_all("/'([a-z][a-z0-9_]*)'/", $check->definition, $matches);

            if ($matches[1] !== []) {
                self::assertEqualsCanonicalizing($enumValues, array_values(array_unique($matches[1])));

                return;
            }
        }

        self::fail("Missing enum CHECK for {$table}.{$column}.");
    }

    public static function invalidDirectOrders(): iterable
    {
        yield 'unknown order status' => [['status' => 'paid']];
        yield 'missing brief' => [['buyer_brief' => null]];
        yield 'whitespace-only brief' => [['buyer_brief' => str_repeat(" \n\t\r", 30)]];
        yield 'leading and trailing whitespace' => [['buyer_brief' => '   Mohon buat poster untuk acara kampus.   ']];
        yield 'short multibyte brief' => [['buyer_brief' => str_repeat('é', 19)]];
        yield 'oversized multibyte brief' => [['buyer_brief' => str_repeat('é', 5001)]];
        yield 'no source' => [['gig_id' => null]];
        yield 'direct with seller proposal' => [['seller_proposal_snapshot' => 'Penawaran pekerjaan dengan lingkup yang disepakati.']];
        yield 'no direct duration' => [['delivery_days_snapshot' => null]];
        yield 'counter zero' => [['stage_version' => 0]];
        yield 'no settlement destination' => [[
            'bank_name_snapshot' => null,
            'bank_account_number_snapshot' => null,
            'bank_account_holder_name_snapshot' => null,
            'qris_image_path_snapshot' => null,
            'qris_holder_name_snapshot' => null,
        ]];
        yield 'incomplete account snapshot' => [[
            'bank_name_snapshot' => 'Bank Demo',
            'bank_account_number_snapshot' => null,
            'bank_account_holder_name_snapshot' => 'Penerima Demo',
            'qris_image_path_snapshot' => 'demo/qris.png',
            'qris_holder_name_snapshot' => 'Penerima Demo',
        ]];
        yield 'incomplete QRIS snapshot' => [[
            'bank_name_snapshot' => 'Bank Demo',
            'bank_account_number_snapshot' => '000123',
            'bank_account_holder_name_snapshot' => 'Penerima Demo',
            'qris_image_path_snapshot' => 'demo/qris.png',
            'qris_holder_name_snapshot' => null,
        ]];
    }

    #[DataProvider('invalidDirectOrders')]
    public function test_check_rejects_invalid_direct_order(array $changes): void
    {
        $order = $this->row('orders', ['transaction_type' => 'direct_gig', 'status' => 'awaiting_payment']);
        $attributes = $this->copyRow($order, ['creation_key' => (string) Str::uuid(), ...$changes]);

        $this->assertSqlState('23514', static fn () => DB::table('orders')->insert($attributes));
    }

    public function test_orders_reject_self_purchase_and_two_sources_but_allow_internal_newlines(): void
    {
        $order = $this->row('orders', ['transaction_type' => 'direct_gig', 'status' => 'awaiting_payment']);
        $attributes = $this->copyRow($order, [
            'creation_key' => (string) Str::uuid(),
            'buyer_brief' => "Gunakan warna biru untuk poster.\nCantumkan jadwal kegiatan.",
        ]);

        $insert = fn (array $changes) => DB::table('orders')->insert(array_replace($attributes, $changes));

        $this->assertSqlState('23514', static fn () => $insert(['buyer_id' => $order->seller_id]));
        $offer = $this->row('orders', ['transaction_type' => 'custom_request'])->price_offer_id;
        $this->assertSqlState('23514', static fn () => $insert(['price_offer_id' => $offer]));

        self::assertTrue($insert([]));
        self::assertSame($attributes['buyer_brief'], DB::table('orders')->where('creation_key', $attributes['creation_key'])->value('buyer_brief'));
    }

    public function test_custom_order_rejects_brief_and_duplicate_offer(): void
    {
        $order = $this->row('orders', ['transaction_type' => 'custom_request']);
        $attributes = $this->copyRow($order, ['creation_key' => (string) Str::uuid()]);

        $this->assertSqlState('23514', static fn () => DB::table('orders')->insert([
            ...$attributes,
            'buyer_brief' => 'Ini hanya boleh diisi pada pesanan langsung.',
        ]));
        $this->assertSqlState('23505', static fn () => DB::table('orders')->insert($attributes));
    }
}
