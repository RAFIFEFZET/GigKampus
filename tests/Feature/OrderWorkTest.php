<?php

namespace Tests\Feature;

use App\Enums\CancellationStatus;
use App\Enums\OrderStatus;
use App\Enums\RevisionStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PharData;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class OrderWorkTest extends PostgresTestCase
{
    public function test_seller_can_deliver_a_private_file_while_cancellation_is_pending(): void
    {
        Storage::fake('private');
        $order = $this->demoOrder(4);
        $file = UploadedFile::fake()->createWithContent('hasil.txt', 'Hasil sesuai brief.');

        $this->actingAs($order->seller)->post("/pesanan/{$order->id}/hasil", [
            'delivery_file' => $file,
            'delivery_note' => 'Berkas final sudah dilampirkan.',
            'stage_version' => $order->stage_version,
        ])->assertRedirect("/pesanan/{$order->id}");

        $delivery = $order->firstDelivery()->sole();
        Storage::disk('private')->assertExists($delivery->file_path);
        self::assertSame('private', Storage::disk('private')->getVisibility($delivery->file_path));
        self::assertSame('Hasil sesuai brief.', Storage::disk('private')->get($delivery->file_path));
        self::assertSame('hasil.txt', $delivery->original_filename);
        self::assertSame('text/plain', $delivery->mime_type);
        self::assertSame(19, $delivery->file_size);
        self::assertSame('Berkas final sudah dilampirkan.', $delivery->delivery_note);
        self::assertNull($delivery->result_url);
        self::assertSame($order->stage_version + 1, $order->refresh()->stage_version);
        self::assertSame(OrderStatus::Delivered, $order->status);
        self::assertSame(CancellationStatus::Pending, $order->cancellation_status);
        $this->assertDatabaseHas('notifications', [
            'order_id' => $order->id, 'user_id' => $order->buyer_id, 'type' => 'delivery.submitted',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'order_id' => $order->id, 'actor_user_id' => $order->seller_id, 'action' => 'delivery.submitted',
        ]);
    }

    public function test_delivery_accepts_a_file_at_the_twenty_mib_limit(): void
    {
        Storage::fake('private');
        $order = $this->demoOrder(4);
        $bytes = 20 * 1024 * 1024;
        $file = UploadedFile::fake()->createWithContent('hasil.txt', str_repeat("abc\n", intdiv($bytes, 4)));

        $this->actingAs($order->seller)->post("/pesanan/{$order->id}/hasil", [
            'delivery_file' => $file,
        ])->assertRedirect("/pesanan/{$order->id}");

        $delivery = $order->firstDelivery()->sole();
        self::assertSame($bytes, $delivery->file_size);
        self::assertSame($bytes, Storage::disk('private')->size($delivery->file_path));
    }

    public static function archiveFormats(): array
    {
        return [
            'DOCX' => ['docx', 'word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'XLSX' => ['xlsx', 'xl/workbook.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'PPTX' => ['pptx', 'ppt/presentation.xml', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'ZIP' => ['zip', 'hasil.txt', 'application/zip'],
        ];
    }

    #[DataProvider('archiveFormats')]
    public function test_delivery_accepts_supported_archive_formats(string $extension, string $mainPart, string $mime): void
    {
        Storage::fake('private');
        $order = $this->demoOrder(4);
        $temporary = tempnam(sys_get_temp_dir(), 'order-delivery');
        $zipPath = $temporary.'.zip';

        try {
            $archive = new PharData($zipPath);
            if ($extension !== 'zip') {
                $archive['[Content_Types].xml'] = <<<XML
                    <?xml version="1.0"?>
                    <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
                        <Override PartName="/{$mainPart}" ContentType="{$mime}.main+xml"/>
                    </Types>
                    XML;
                $archive['_rels/.rels'] = <<<XML
                    <?xml version="1.0"?>
                    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                        <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="{$mainPart}"/>
                    </Relationships>
                    XML;
            }
            $archive[$mainPart] = $extension === 'zip' ? 'Hasil sesuai brief.' : '<?xml version="1.0"?><hasil/>';
            $file = UploadedFile::fake()->createWithContent('hasil.'.strtoupper($extension), file_get_contents($zipPath));

            $this->actingAs($order->seller)->post("/pesanan/{$order->id}/hasil", [
                'delivery_file' => $file,
            ])->assertRedirect("/pesanan/{$order->id}");

            $delivery = $order->firstDelivery()->sole();
            self::assertSame($mime, $delivery->mime_type);
        } finally {
            unset($archive);
            unlink($temporary);
            if (is_file($zipPath)) {
                unlink($zipPath);
            }
        }
    }

    public function test_delivery_requires_exactly_one_allowed_file_or_https_url(): void
    {
        Storage::fake('private');
        $order = $this->demoOrder(4);
        $path = "/pesanan/{$order->id}/hasil";
        $this->actingAs($order->seller);

        $this->postJson($path, [])->assertUnprocessable();
        $this->postJson($path, ['result_url' => 'http://example.test/hasil'])->assertUnprocessable();
        $this->postJson($path, ['result_url' => 'https://'])->assertUnprocessable();
        $this->postJson($path, [
            'delivery_file' => UploadedFile::fake()->createWithContent('hasil.txt', 'Hasil sesuai brief.'),
            'result_url' => 'https://example.test/hasil',
        ])->assertUnprocessable();
        $this->postJson($path, [
            'delivery_file' => UploadedFile::fake()->createWithContent('hasil.pdf', 'Berkas ini bukan PDF.'),
        ])->assertUnprocessable();
        $this->postJson($path, [
            'delivery_file' => UploadedFile::fake()->createWithContent('hasil.txt', ''),
        ])->assertUnprocessable();
        $this->postJson($path, [
            'delivery_file' => UploadedFile::fake()->createWithContent('hasil.txt', str_repeat('a', 20 * 1024 * 1024 + 1)),
        ])->assertUnprocessable();

        self::assertSame(OrderStatus::InProgress, $order->refresh()->status);
        self::assertSame([], Storage::disk('private')->allFiles());
        self::assertSame(0, $order->deliveries()->count());
    }

    public function test_only_the_order_seller_may_deliver_and_disputed_orders_reject_work(): void
    {
        $order = $this->demoOrder(4);
        $path = "/pesanan/{$order->id}/hasil";
        $result = ['result_url' => 'https://example.test/hasil'];
        $stranger = User::whereEmail('unverified.demo@apps.ipb.ac.id')->firstOrFail();
        $this->actingAs($stranger)->postJson($path, $result)->assertForbidden();
        $this->actingAs($order->buyer)->postJson($path, $result)->assertForbidden();

        $disputed = $this->demoOrder(10);
        $this->actingAs($disputed->seller)->postJson("/pesanan/{$disputed->id}/hasil", $result)->assertConflict();
        $this->actingAs($disputed->buyer)->postJson("/pesanan/{$disputed->id}/revisi", [
            'revision_note' => 'Mohon perbaiki catatan tanggal kegiatan.',
        ])->assertConflict();
        $this->postJson("/pesanan/{$disputed->id}/selesai")->assertConflict();
        $this->postJson("/pesanan/{$disputed->id}/ulasan", ['rating' => 5])->assertConflict();
    }

    public static function revisionStartOptions(): array
    {
        return ['without an explicit start' => [false], 'after an explicit start' => [true]];
    }

    #[DataProvider('revisionStartOptions')]
    public function test_delivery_completes_the_current_revision_and_preserves_the_first_delivery(bool $start): void
    {
        $order = $this->demoOrder(5);
        $first = $order->firstDelivery()->sole();
        $stage = $order->stage_version;
        $path = "/pesanan/{$order->id}";
        $note = 'Mohon perbaiki tanggal kegiatan sesuai brief.';

        $this->actingAs($order->seller)->postJson($path.'/revisi', ['revision_note' => $note])->assertForbidden();
        $this->actingAs($order->buyer)->postJson($path.'/revisi', ['revision_note' => 'Terlalu pendek.'])->assertUnprocessable();
        $this->post($path.'/revisi', ['revision_note' => $note, 'stage_version' => $stage])->assertRedirect($path);
        $revision = $order->revisions()->sole();
        self::assertSame(1, $revision->revision_no);
        self::assertSame($note, $revision->revision_note);
        self::assertSame(RevisionStatus::Requested, $revision->status);
        self::assertSame(0, $order->refresh()->remainingRevisions());
        self::assertSame(OrderStatus::Revision, $order->status);
        self::assertSame(++$stage, $order->stage_version);
        $this->postJson($path.'/revisi', ['revision_note' => $note])->assertConflict();
        $this->postJson($path."/revisi/{$revision->id}/mulai")->assertForbidden();

        $this->actingAs($order->seller);
        $unrelatedRevision = $this->demoOrder(6)->revisions()->sole();
        $this->postJson($path.'/hasil', [
            'result_url' => 'https://example.test/hasil-baru', 'revision_id' => $unrelatedRevision->id,
        ])->assertConflict();
        $this->postJson($path."/revisi/{$unrelatedRevision->id}/mulai")->assertNotFound();
        if ($start) {
            $this->post($path."/revisi/{$revision->id}/mulai")->assertRedirect($path);
            self::assertSame(RevisionStatus::InProgress, $revision->refresh()->status);
            self::assertSame($stage, $order->refresh()->stage_version);
        }

        $this->post($path.'/hasil', [
            'result_url' => 'https://example.test/hasil-baru', 'revision_id' => $revision->id, 'stage_version' => $stage,
        ])->assertRedirect($path);
        $replacement = $revision->delivery()->sole();
        self::assertSame('https://example.test/hasil-baru', $replacement->result_url);
        self::assertNull($replacement->file_path);
        self::assertSame(RevisionStatus::Completed, $revision->refresh()->status);
        self::assertTrue($revision->completed_at->equalTo($replacement->submitted_at));
        self::assertSame(OrderStatus::Delivered, $order->refresh()->status);
        self::assertSame($stage + 1, $order->stage_version);
        self::assertSame($first->getRawOriginal(), $order->firstDelivery()->sole()->getRawOriginal());
        $this->postJson($path.'/hasil', ['result_url' => 'https://example.test/duplikat'])->assertConflict();
        $this->actingAs($order->buyer)->postJson($path.'/revisi', ['revision_note' => $note])->assertConflict();
    }

    public function test_buyer_completion_voids_pending_cancellation_and_archives_chat(): void
    {
        $order = $this->demoOrder(5);
        $order->requestCancellation($order->seller, 'Saya perlu membahas perubahan jadwal.');
        $order->save();
        $path = "/pesanan/{$order->id}/selesai";
        $stage = $order->stage_version;

        $this->actingAs($order->seller)->postJson($path)->assertForbidden();
        $this->actingAs($order->buyer)->postJson($path, ['stage_version' => $stage - 1])->assertConflict();
        $this->post($path, ['stage_version' => $stage])->assertRedirect("/pesanan/{$order->id}");

        self::assertSame(OrderStatus::Completed, $order->refresh()->status);
        self::assertSame($stage + 1, $order->stage_version);
        self::assertSame(CancellationStatus::Voided, $order->cancellation_status);
        self::assertSame('Pesanan sudah selesai', $order->cancellation_response_reason);
        self::assertNotNull($order->cancellation_closed_at);
        self::assertTrue($order->chatRoom()->sole()->isArchived());
        $this->postJson($path)->assertConflict();
    }

    public function test_review_is_available_once_to_the_buyer_after_completion(): void
    {
        $order = $this->demoOrder(15);
        $path = "/pesanan/{$order->id}/ulasan";
        $delivered = $this->demoOrder(5);
        $hidden = $this->demoOrder(13);

        $this->actingAs($order->seller)->postJson($path, ['rating' => 5])->assertForbidden();
        $this->actingAs($order->buyer)->postJson("/pesanan/{$delivered->id}/ulasan", ['rating' => 5])->assertConflict();
        $this->actingAs($hidden->buyer)->postJson("/pesanan/{$hidden->id}/ulasan", ['rating' => 5])->assertConflict();
        $this->actingAs($order->buyer)->postJson($path, ['rating' => 6])->assertUnprocessable();
        $this->postJson($path, ['rating' => 2.5])->assertUnprocessable();
        $this->postJson($path, ['rating' => true])->assertUnprocessable();
        $this->post($path, ['rating' => 4])->assertRedirect("/pesanan/{$order->id}");
        $this->postJson($path, ['rating' => 1, 'comment' => 'Percobaan mengganti ulasan lama.'])->assertConflict();

        $review = $order->review()->sole();
        self::assertSame($order->buyer_id, $review->reviewer_id);
        self::assertSame($order->seller_id, $review->seller_id);
        self::assertSame(4, $review->rating);
        self::assertNull($review->comment);
        $this->assertSqlState('23514', static fn () => DB::table('reviews')
            ->where('id', $review->id)->update(['rating' => 2]));
        $this->assertSqlState('23514', static fn () => DB::table('reviews')->where('id', $review->id)->delete());
    }

    private function demoOrder(int $number): Order
    {
        return Order::where('creation_key', sprintf('00000000-0000-4000-8000-%012d', $number))->firstOrFail();
    }
}
