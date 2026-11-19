<?php

namespace App\Services;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;

class KtmOcrService
{
    /** @return list<string> */
    public function candidates(string $path): array
    {
        $disk = Storage::disk('private');
        if (! $disk->exists($path)) {
            throw new RuntimeException('KTM OCR image is missing.');
        }

        try {
            $result = Process::timeout(30)->run([
                'tesseract', $disk->path($path), 'stdout', '-l', 'ind+eng', '--psm', '6',
            ]);
        } catch (ProcessTimedOutException|ProcessRuntimeException) {
            throw new RuntimeException('KTM OCR process could not finish.');
        }

        if (! $result->successful()) {
            throw new RuntimeException('KTM OCR process did not succeed.');
        }

        // A value must be adjacent to an explicit NIM label. Keep the read
        // characters intact and only normalize their case and spacing.
        preg_match_all(
            '/^\h*NIM(?:\h*[:=]\h*|\h+|(?=\R))\h*(?:\R\h*)?([A-Z0-9]+(?:\h+[A-Z0-9]+)*)\h*$/mi',
            $result->output(),
            $matches,
        );

        $candidates = [];
        foreach ($matches[1] as $read) {
            $candidate = strtoupper(preg_replace('/\h+/', '', $read));
            if (strlen($candidate) <= 20 && preg_match('/[0-9]/', $candidate) === 1) {
                $candidates[] = $candidate;
            }
        }

        return array_values(array_unique($candidates));
    }
}
