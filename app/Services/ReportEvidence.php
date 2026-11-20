<?php

namespace App\Services;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

final class ReportEvidence
{
    private const FILE_TYPES = [
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    public static function rules(): array
    {
        return [
            'attachment' => ['nullable', 'file', 'max:20480'],
            'evidence_url' => ['nullable', 'string', 'url:https', 'max:2048', 'prohibits:attachment'],
        ];
    }

    public static function transaction(string $directory, array $input, Closure $write): mixed
    {
        $path = null;
        $record = null;

        try {
            return DB::transaction(function () use ($directory, $input, $write, &$path, &$record) {
                // The caller locks and authorizes the target before invoking this callback.
                return $write(function () use ($directory, $input, &$path): array {
                    $file = $input['attachment'] ?? null;

                    return $file === null
                        ? ['evidence_url' => $input['evidence_url'] ?? null]
                        : self::store($directory, $file, $path);
                }, $record);
            });
        } catch (Throwable $exception) {
            // An after-commit broadcast failure must preserve a committed file.
            if ($path !== null && ($record === null || ! $record->newQuery()->whereKey($record->id)->exists())) {
                Storage::disk('private')->delete($path);
            }

            throw $exception;
        }
    }

    private static function store(string $directory, UploadedFile $file, ?string &$path): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = MimeTypes::getDefault()->guessMimeType($file->getPathname());
        $size = $file->getSize();
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $file->getClientOriginalName());
        if (! is_string($name) || Str::trim($name) === '' || $size < 1
            || ! in_array($mime, self::FILE_TYPES[$extension] ?? [], true)) {
            throw ValidationException::withMessages([
                'attachment' => 'Unggah berkas PDF, DOCX, XLSX, PPTX, TXT, CSV, JPG, PNG, atau ZIP yang valid.',
            ]);
        }
        $filename = Str::uuid().'.'.$extension;
        $path = $directory.'/'.$filename;
        if ($file->storeAs($directory, $filename, ['disk' => 'private', 'visibility' => 'private']) === false) {
            throw ValidationException::withMessages(['attachment' => 'Bukti gagal disimpan. Silakan coba kembali.']);
        }

        return [
            'file_path' => $path,
            'original_filename' => mb_substr($name, 0, 255),
            'mime_type' => $mime,
            'file_size' => $size,
        ];
    }
}
