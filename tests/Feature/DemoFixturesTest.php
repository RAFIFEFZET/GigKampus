<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class DemoFixturesTest extends PostgresTestCase
{
    public function test_demo_seeding_preserves_existing_rows_and_all_referenced_files_are_local(): void
    {
        $schema = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
        $tables = array_diff(array_column($schema, 'tablename'), ['jobs', 'failed_jobs', 'migrations']);
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
            self::assertGreaterThan(0, $counts[$table], "Demo has no rows in {$table}");
        }

        $buyer = $this->row('users', ['email' => 'buyer.demo@apps.ipb.ac.id']);
        DB::table('users')->where('id', $buyer->id)->update(['bio' => 'Profil yang diubah ketika presentasi.']);
        self::assertSame(0, Artisan::call('db:seed', ['--class' => DatabaseSeeder::class]), Artisan::output());

        foreach ($counts as $table => $count) {
            self::assertSame($count, DB::table($table)->count(), $table);
        }
        self::assertSame('Profil yang diubah ketika presentasi.', DB::table('users')->where('id', $buyer->id)->value('bio'));

        foreach ([
            'users' => ['avatar_path' => 'public', 'qris_image_path' => 'private'],
            'gigs' => ['portfolio_path' => 'public'],
            'orders' => ['qris_image_path_snapshot' => 'private'],
        ] as $table => $paths) {
            foreach ($paths as $column => $disk) {
                foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $path) {
                    self::assertTrue(Storage::disk($disk)->exists($path), "{$disk}:{$path}");
                }
            }
        }

        foreach ([
            'ktm_verification_requests',
            'payment_proofs',
            'order_deliveries',
            'chat_messages',
            'reports',
            'report_clarification_responses',
        ] as $table) {
            $disk = Storage::disk('private');
            foreach (DB::table($table)->whereNotNull('file_path')->get() as $file) {
                self::assertTrue($disk->exists($file->file_path), $file->file_path);
                self::assertSame($file->file_size, $disk->size($file->file_path));
                self::assertSame($file->mime_type, $disk->mimeType($file->file_path));
            }
        }

        self::assertTrue(DB::table('gigs')->whereNotNull('portfolio_path')->exists());
        self::assertTrue(DB::table('order_deliveries')->whereNotNull('file_path')->exists());
    }

    public static function ktmImages(): iterable
    {
        yield 'one readable candidate' => ['ktm-clear.png', ['DEMOIPB119']];
        yield 'unreadable image' => ['ktm-unreadable.png', []];
        yield 'more than one candidate is ambiguous' => ['ktm-ambiguous.png', ['DEMOIPB198', 'DEMOIPB199']];
    }

    #[DataProvider('ktmImages')]
    public function test_tesseract_finds_the_expected_candidates_in_demo_ktm(string $fixture, array $candidates): void
    {
        $disk = Storage::disk('private');
        $file = "demo/{$fixture}";
        self::assertTrue($disk->exists($file), $file);
        self::assertFalse(Storage::disk('public')->exists($file), $file);

        $result = Process::timeout(30)->run([
            'tesseract', $disk->path($file), 'stdout', '-l', 'ind+eng', '--psm', '6',
        ]);
        self::assertTrue($result->successful(), $result->errorOutput());
        preg_match_all('/\bDEMOIPB[0-9]{3}\b/', strtoupper($result->output()), $matches);

        self::assertEqualsCanonicalizing($candidates, array_values(array_unique($matches[0])));
    }
}
