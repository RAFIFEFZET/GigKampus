<?php

namespace App\Services\Orders;

use App\Enums\CancellationStatus;
use App\Enums\OrderStatus;
use App\Enums\RevisionStatus;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

final class OrderDeliveryService
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

    public function submit(
        User $seller,
        Order $order,
        ?UploadedFile $file,
        ?string $url,
        ?string $note,
        ?int $revisionId,
        ?int $stageVersion,
    ): OrderDelivery {
        $writtenPath = null;

        try {
            return OrderTransaction::run($seller, $order, function (Order $locked) use (
                $seller, $file, $url, $note, $revisionId, $stageVersion, &$writtenPath,
            ): OrderDelivery {
                abort_unless($locked->hasSeller($seller), 403);
                $locked->assertStageVersion($stageVersion);

                $revision = null;
                if ($locked->status === OrderStatus::Revision) {
                    $revision = $locked->revisions()
                        ->whereIn('status', [RevisionStatus::Requested->value, RevisionStatus::InProgress->value])
                        ->lockForUpdate()->first();
                    if ($revision === null) {
                        throw new DomainException('A revision delivery requires an open revision.');
                    }
                }
                if ($revisionId !== null && $revision?->id !== $revisionId) {
                    throw new DomainException('The selected revision is no longer open for this order.');
                }

                $at = now();
                $before = [
                    ...$locked->only(['status', 'stage_version']),
                    'revision_id' => $revision?->id,
                    'revision_status' => $revision?->status->value,
                ];
                if ($revision !== null) {
                    $revision->complete($seller, $locked, $at);
                }
                $locked->deliver($seller);

                $attachment = $file === null ? [] : $this->storeFile($locked, $file, $writtenPath);
                $delivery = $locked->deliveries()->forceCreate([
                    ...$attachment,
                    'revision_id' => $revision?->id,
                    'result_url' => $url,
                    'delivery_note' => $note,
                    'submitted_at' => $at,
                ]);
                $revision?->saveOrFail();
                $locked->saveOrFail();

                OrderActivity::record(
                    $locked, $seller, 'delivery.submitted',
                    'Hasil pesanan dikirim', 'Periksa hasil pekerjaan terbaru pada detail pesanan.',
                    $before,
                    [
                        ...$locked->only(['status', 'stage_version']),
                        'delivery_id' => $delivery->id,
                        'revision_id' => $revision?->id,
                        'revision_status' => $revision?->status->value,
                    ],
                );

                return $delivery;
            });
        } catch (Throwable $exception) {
            if ($writtenPath !== null) {
                Storage::disk('private')->delete($writtenPath);
            }

            throw $exception;
        }
    }

    public function complete(User $buyer, Order $order, ?int $stageVersion): void
    {
        OrderTransaction::run($buyer, $order, function (Order $locked) use ($buyer, $stageVersion): void {
            abort_unless($locked->hasBuyer($buyer), 403);
            $locked->assertStageVersion($stageVersion);

            $fields = [
                'status', 'stage_version', 'cancellation_status', 'cancellation_version',
                'cancellation_response_reason', 'cancellation_closed_at',
            ];
            $before = $locked->only($fields);
            $voidsCancellation = $locked->cancellation_status === CancellationStatus::Pending;
            $locked->complete($buyer);
            $locked->saveOrFail();
            OrderActivity::archiveChat($locked);

            OrderActivity::record(
                $locked, $buyer, 'order.completed',
                'Pesanan selesai', 'Pemesan telah menyelesaikan pesanan.',
                $before, $locked->only($fields),
                $voidsCancellation ? $locked->cancellation_response_reason : null,
            );
        });
    }

    private function storeFile(Order $order, UploadedFile $file, ?string &$writtenPath): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = MimeTypes::getDefault()->guessMimeType($file->getPathname());
        $filename = preg_replace('/[\x00-\x1f\x7f]/u', '', $file->getClientOriginalName());
        $size = $file->getSize();

        if ($filename === null || $size < 1 || ! in_array($mime, self::FILE_TYPES[$extension] ?? [], true)) {
            throw ValidationException::withMessages([
                'delivery_file' => 'Unggah berkas PDF, DOCX, XLSX, PPTX, TXT, CSV, JPG, PNG, atau ZIP yang valid.',
            ]);
        }

        $directory = 'order-deliveries/'.$order->id;
        $name = Str::uuid().'.'.$extension;
        $writtenPath = $directory.'/'.$name;
        if ($file->storeAs($directory, $name, ['disk' => 'private', 'visibility' => 'private']) === false) {
            throw ValidationException::withMessages([
                'delivery_file' => 'Berkas hasil gagal disimpan. Silakan coba kembali.',
            ]);
        }

        return [
            'file_path' => $writtenPath,
            'original_filename' => Str::limit($filename, 255, ''),
            'mime_type' => $mime,
            'file_size' => $size,
        ];
    }
}
