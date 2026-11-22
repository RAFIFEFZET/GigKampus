<?php

namespace Tests\Feature;

use App\Services\KtmOcrService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KtmOcrServiceTest extends TestCase
{
    public function test_ocr_accepts_nim_punctuation_and_excludes_hash_text(): void
    {
        $disk = Storage::fake('private');
        $disk->put('photo.png', 'unused fake process input');
        Process::fake([
            '*' => Process::result(output: "# DEMOIPB000\nnim. DEMO IPB 119 # DEMOIPB000\nNIM : demoipb120\nNIM:\n# DEMOIPB000\n"),
        ]);

        self::assertSame(['DEMOIPB119', 'DEMOIPB120'], app(KtmOcrService::class)->candidates('photo.png'));
    }

    public function test_ocr_retries_a_synthetic_sideways_photo(): void
    {
        $disk = Storage::fake('private');
        $file = $disk->path('sideways.png');
        Process::run(['magick', resource_path('demo-fixtures/ktm-clear.png'), '-rotate', '90', $file])->throw();

        self::assertSame(['DEMOIPB119'], app(KtmOcrService::class)->candidates('sideways.png'));
    }

    public function test_ocr_retries_layout_when_grouped_text_is_not_a_candidate(): void
    {
        $disk = Storage::fake('private');
        $disk->put('photo.png', 'unused fake process input');
        Process::fake(static function (PendingProcess $process) {
            $output = $process->command[0] === 'tesseract' ? match (end($process->command)) {
                '6' => "NIM: DEMOIPB120 99%\n",
                '11' => "NIM: 120 LAB\n",
                '4' => "NIM.\nDEMOIPB119\n",
            } : '';

            return Process::result(output: $output);
        });

        self::assertSame(['DEMOIPB119'], app(KtmOcrService::class)->candidates('photo.png'));
    }
}
