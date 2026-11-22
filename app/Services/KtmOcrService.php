<?php

namespace App\Services;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;

class KtmOcrService
{
    private const MAX_DIMENSION = 1800;

    private const SCAN_BUDGET_SECONDS = 15;

    /** @return list<string> */
    public function candidates(string $path): array
    {
        $disk = Storage::disk('private');
        if (! $disk->exists($path)) {
            throw new RuntimeException('KTM OCR image is missing.');
        }

        $source = $disk->path($path);
        $deadline = hrtime(true) + self::SCAN_BUDGET_SECONDS * 1_000_000_000;
        $size = @getimagesize($source);
        if ($size === false || max($size[0], $size[1]) <= self::MAX_DIMENSION) {
            $candidates = $this->read($source, 6, $deadline);
            if ($candidates !== []) {
                return $candidates;
            }
        }

        $image = tempnam(sys_get_temp_dir(), 'gigkampus-ktm-');
        if ($image === false) {
            throw new RuntimeException('KTM OCR temporary image is unavailable.');
        }

        $dimension = self::MAX_DIMENSION.'x'.self::MAX_DIMENSION;
        $contrast = ['-contrast-stretch', '0.5%x0.5%', '-unsharp', '0x1+0.7+0.02'];
        $preprocessing = [
            ['-resize', $dimension.'>'],
            ['-resize', $dimension.'>', ...$contrast],
            ['-resize', $dimension, ...$contrast, '-lat', '31x31+5%'],
        ];

        try {
            foreach ($preprocessing as $options) {
                foreach ([0, 270, 90, 180] as $rotation) {
                    $output = $this->run([
                        'magick', '-limit', 'memory', '64MiB', '-limit', 'map', '128MiB',
                        '-limit', 'disk', '256MiB', $source.'[0]', '-auto-orient', '-rotate', (string) $rotation,
                        '-colorspace', 'Gray', ...$options, '-strip', '-depth', '8', 'png:'.$image,
                    ], $deadline);
                    if ($output === null) {
                        return [];
                    }

                    foreach ([6, 11, 4] as $mode) {
                        $candidates = $this->read($image, $mode, $deadline);
                        if ($candidates !== []) {
                            return $candidates;
                        }
                    }
                }
            }
        } finally {
            unlink($image);
        }

        return [];
    }

    /** @return list<string> */
    private function read(string $image, int $mode, int $deadline): array
    {
        $output = $this->run(['tesseract', $image, 'stdout', '-l', 'ind+eng', '--psm', (string) $mode], $deadline);
        $lines = preg_split('/\R/', $output ?? '');
        $candidates = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^\h*NIM(?=\h|[.:=]|$)\h*\.?\h*[:=]?\h*(.*)$/i', $line, $match) !== 1) {
                continue;
            }

            // An adjacent value can wrap to the next line. Hashes are not identifiers.
            $read = trim(explode('#', $match[1] === '' ? ($lines[$index + 1] ?? '') : $match[1], 2)[0]);
            // A split identifier must end in a numeric group, not adjacent label text.
            if (preg_match('/^(?:[A-Z0-9]+|[A-Z0-9]+(?:\h+[A-Z0-9]+)*\h+[0-9]+)$/i', $read) !== 1) {
                continue;
            }
            $candidate = strtoupper(preg_replace('/\h+/', '', $read));
            if (strlen($candidate) <= 20 && preg_match('/[0-9]/', $candidate) === 1) {
                $candidates[] = $candidate;
            }
        }

        return array_values(array_unique($candidates));
    }

    private function run(array $command, int $deadline): ?string
    {
        $remaining = intdiv($deadline - hrtime(true), 1_000_000_000);
        if ($remaining < 1) {
            return null;
        }

        try {
            $result = Process::timeout(min(4, $remaining))->env(['OMP_THREAD_LIMIT' => '1', 'MAGICK_THREAD_LIMIT' => '1'])
                ->run($command);
        } catch (ProcessTimedOutException) {
            return null;
        } catch (ProcessRuntimeException) {
            throw new RuntimeException('KTM OCR process could not finish.');
        }

        if (! $result->successful()) {
            throw new RuntimeException('KTM OCR process did not succeed.');
        }

        return $result->output();
    }
}
