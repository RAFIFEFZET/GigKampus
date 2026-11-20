<?php

namespace Tests\Feature;

use App\Enums\CancellationStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Models\Admin;
use App\Models\ChatRoom;
use App\Models\Gig;
use App\Models\Notification;
use App\Models\Order;
use App\Models\ReportClarification;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ChatService;
use App\Services\ClarificationService;
use App\Services\ReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class ReportMediationTest extends PostgresTestCase
{
    private const DESCRIPTION = 'Saya meminta pemeriksaan riwayat dan bukti simulasi transaksi ini.';

    public function test_order_complaint_freezes_all_writes_but_allows_chat_and_refund_evidence(): void
    {
        $order = $this->order(8);
        $buyer = $order->buyer;
        $seller = $order->seller;
        $stage = $order->stage_version;
        $adminCount = Admin::count();
        $response = $this->actingAs($buyer, 'web')->post($this->submitUrl(ReportType::OrderComplaint, $order->id), $this->input());
        $report = $order->reports()->sole();
        $response->assertRedirectToRoute('reports.show', $report);
        self::assertSame(OrderStatus::Disputed, $order->refresh()->status);
        self::assertSame($stage, $order->stage_version);
        self::assertSame(OrderStatus::Cancelled, $report->order_status_before_dispute);
        self::assertSame($stage, $report->order_stage_version);
        self::assertSame(RefundStatus::Pending, $order->payment->refund_status);
        $this->assertDatabaseHas('audit_logs', ['report_id' => $report->id, 'order_id' => $order->id, 'action' => 'order.disputed']);
        self::assertSame($adminCount, $report->notifications()->whereNotNull('admin_id')->count());
        $this->assertDatabaseHas('notifications', ['report_id' => $report->id, 'user_id' => $seller->id, 'type' => 'order.disputed']);
        $this->assertDatabaseMissing('notifications', ['report_id' => $report->id, 'user_id' => $buyer->id]);

        $this->get(route('orders.show', $order))->assertOk()->assertSee('Pesanan dalam sengketa')->assertDontSee('Ajukan Komplain');
        $this->post(route('orders.refund.confirm', $order), ['confirmed' => true])->assertConflict();
        $this->post(route('orders.complete', $order))->assertConflict();
        $this->post(route('orders.revisions.store', $order), ['revision_note' => self::DESCRIPTION])->assertConflict();
        $this->post(route('orders.reviews.store', $order), ['rating' => 5])->assertConflict();
        $this->post(route('orders.cancel.request', $order), ['reason' => self::DESCRIPTION])->assertConflict();
        $this->patch(route('orders.payment.method', $order), ['payment_method' => 'bank_transfer'])->assertConflict();
        $this->post(route('orders.payment.proofs.store', $order), ['proof' => $this->png()])->assertConflict();
        $this->actingAs($seller, 'web')->post(route('orders.deliveries.store', $order), ['result_url' => 'https://example.test/hasil'])->assertConflict();
        $this->post($this->submitUrl(ReportType::OrderComplaint, $order->id), $this->input())->assertConflict();

        Storage::fake('private');
        $refund = app(ChatService::class)->send($seller, $order->chatRoom, [
            'client_message_key' => Str::uuid()->toString(),
            'message_type' => 'refund_proof', 'attachment' => $this->png(),
        ]);
        Storage::disk('private')->assertExists($refund->file_path);
        self::assertNull($refund->read_at);
        self::assertFalse($order->chatRoom->isArchived());
    }

    public function test_ineligible_and_final_orders_cannot_reopen_and_dismissal_tracks_the_stage(): void
    {
        $order = $this->order(12);
        $buyer = $order->buyer;
        $seller = $order->seller;
        $this->actingAs($buyer, 'web');
        foreach ([1, 7, 9, 12, 14] as $fixture) {
            $blocked = $this->order($fixture);
            $this->post($this->submitUrl(ReportType::OrderComplaint, $blocked->id), $this->input())->assertConflict();
        }
        $outsider = User::where('email', 'seller2.demo@apps.ipb.ac.id')->sole();
        $this->actingAs($outsider, 'web')->post($this->submitUrl(ReportType::OrderComplaint, $order->id), $this->input())->assertForbidden();
        $this->actingAs($buyer, 'web')->post(route('orders.revisions.store', $order), ['revision_note' => self::DESCRIPTION])->assertRedirect();
        self::assertSame(5, $order->refresh()->stage_version);
        $this->post($this->submitUrl(ReportType::OrderComplaint, $order->id), $this->input())->assertRedirect();
        $report = $order->reports()->latest('id')->firstOrFail();
        $admin = Admin::firstOrFail();
        $this->actingAs($admin, 'admin')->post(route('admin.reports.claim', $report))->assertRedirect();
        $this->post(route('admin.reports.decide', $report), $this->decision('dismiss_restore', 'dismissed'))->assertRedirect();
        self::assertSame(OrderStatus::Revision, $order->refresh()->status);
        self::assertSame(5, $order->stage_version);
        self::assertSame(1, $order->revisions()->count());
        self::assertSame(CancellationStatus::Withdrawn, $order->cancellation_status);
        self::assertSame(RefundStatus::None, $order->payment->refund_status);
        $this->actingAs($buyer, 'web')->post($this->submitUrl(ReportType::OrderComplaint, $order->id), $this->input())->assertConflict();
        $this->actingAs($seller, 'web')->post($this->submitUrl(ReportType::OrderComplaint, $order->id), $this->input())->assertRedirect();
    }

    public function test_four_non_order_reports_validate_inputs_and_preserve_a_final_order(): void
    {
        $order = $this->order(7);
        $buyer = $order->buyer;
        $seller = $order->seller;
        $request = ServiceRequest::publiclyVisible()->firstOrFail();
        $gig = Gig::publiclyVisible()->firstOrFail();
        $prechat = ChatRoom::whereNull('order_id')->where('buyer_id', $buyer->id)->firstOrFail();

        $this->actingAs($buyer, 'web');
        $url = $this->submitUrl(ReportType::GigReport, $gig->id);
        $this->post($url, ['category' => 'unknown', 'description' => '      pendek     '])->assertSessionHasErrors(['category', 'description']);
        $this->post($url, $this->input(['evidence_url' => 'http://example.test']))->assertSessionHasErrors('evidence_url');
        $this->post($url, $this->input(['attachment' => $this->png(), 'evidence_url' => 'https://example.test']))->assertSessionHasErrors('evidence_url');
        $this->post($url, $this->input(['attachment' => UploadedFile::fake()->create('large.pdf', 20481, 'application/pdf')]))->assertSessionHasErrors('attachment');
        $this->post($url, $this->input(['attachment' => UploadedFile::fake()->createWithContent('invalid.pdf', 'Bukan PDF')]))->assertSessionHasErrors('attachment');

        foreach ([
            [ReportType::GigReport, $gig->id],
            [ReportType::RequestReport, $request->id],
            [ReportType::ReviewReport, $order->review->id],
            [ReportType::ChatReport, $order->chatRoom->id],
            [ReportType::ChatReport, $prechat->id],
        ] as [$type, $id]) {
            $url = $this->submitUrl($type, $id);
            $this->post($url, $this->input(['description' => '  '.self::DESCRIPTION.'  ', 'evidence_url' => 'https://example.test/pendukung']))->assertRedirect();
            $this->assertDatabaseHas('reports', [
                'reporter_id' => $buyer->id, $type->targetColumn() => $id,
                'description' => self::DESCRIPTION, 'order_id' => null,
            ]);
            $this->post($url, $this->input())->assertConflict();
        }
        $this->actingAs($seller, 'web')->post($this->submitUrl(ReportType::ChatReport, $order->chatRoom->id), $this->input())->assertRedirect();
        $outsider = User::where('email', 'seller2.demo@apps.ipb.ac.id')->sole();
        $this->actingAs($outsider, 'web')->post($this->submitUrl(ReportType::ChatReport, $order->chatRoom->id), $this->input())->assertForbidden();
        $unverified = User::query()->where('is_verified', false)->firstOrFail();
        $this->actingAs($unverified, 'web')->post($this->submitUrl(ReportType::GigReport, $gig->id), $this->input())->assertForbidden();
        self::assertSame(OrderStatus::Completed, $order->refresh()->status);
        self::assertTrue($order->chatRoom->isArchived());
    }

    public function test_admin_private_case_and_chat_access_is_scoped_and_does_not_mark_read(): void
    {
        Storage::fake('private');
        $order = $this->order(5);
        $admin = Admin::firstOrFail();
        $other = Admin::forceCreate(['name' => 'Admin Uji Kasus', 'email' => 'kasus@example.test', 'password_hash' => 'sandi-uji-kasus']);
        $proof = $order->payment->verifiedProof;
        $delivery = $order->deliveries()->firstOrFail();
        foreach ([$proof->file_path, $delivery->file_path, $order->qris_image_path_snapshot] as $path) {
            Storage::disk('private')->put($path, file_get_contents(resource_path('demo-fixtures/proof-bank.png')));
        }
        $message = app(ChatService::class)->send($order->seller, $order->chatRoom, [
            'client_message_key' => Str::uuid()->toString(),
            'message_body' => 'Pesan belum dibaca oleh mahasiswa.',
            'attachment' => UploadedFile::fake()->createWithContent('catatan.txt', 'Lampiran pesan privat'),
        ]);
        $report = app(ReportService::class)->submit($order->buyer, ReportType::OrderComplaint, $order->id, $this->input(['attachment' => $this->png()]));
        $urls = [
            $this->fileUrl('report_evidence', $report->id),
            $this->fileUrl('chat_attachment', $message->id),
            $this->fileUrl('payment_proof', $proof->id),
            $this->fileUrl('order_delivery', $delivery->id),
            $this->fileUrl('order_qris_snapshot', $order->id),
        ];
        $chatUrl = route('admin.reports.show', ['report' => $report, 'chat' => 1]);

        $this->actingAs($admin, 'admin')->get($chatUrl)->assertForbidden();
        foreach ($urls as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post(route('admin.reports.claim', $report))->assertRedirect();
        $this->get($chatUrl)->assertOk()->assertSee($message->message_body);
        self::assertNull($message->refresh()->read_at);
        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
        $this->get($this->fileUrl('payment_proof', $this->order(2)->payment->proofs()->sole()->id))->assertForbidden();
        $this->actingAs($other, 'admin')->get($chatUrl)->assertForbidden();
        foreach ($urls as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post(route('admin.reports.claim', $report))->assertConflict();
        $this->post(route('admin.reports.decide', $report), $this->decision('restore_previous'))->assertForbidden();
        $this->actingAs($admin, 'admin')->post(route('admin.reports.decide', $report), $this->decision('restore_previous'))->assertRedirect();
        $this->get($chatUrl)->assertForbidden();
        foreach ($urls as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get(route('admin.reports.show', $report))->assertOk()->assertDontSee($message->message_body)->assertSee('Putusan akhir');
        self::assertNull($message->refresh()->read_at);
        $this->post(route('admin.reports.decide', $report), $this->decision('complete_delivery'))->assertForbidden();
    }

    public function test_clarifications_are_private_accept_multiple_late_replies_and_remain_final(): void
    {
        Storage::fake('private');
        $order = $this->order(2);
        $report = app(ReportService::class)->submit($order->buyer, ReportType::OrderComplaint, $order->id, $this->input());
        $admin = Admin::firstOrFail();
        $seller = $order->seller;
        $this->actingAs($admin, 'admin')->post(route('admin.reports.claim', $report))->assertRedirect();
        $requestUrl = route('admin.reports.clarify', $report);
        $this->post($requestUrl, ['recipient_user_id' => $seller->id, 'question' => 'abc'])->assertSessionHasErrors('question');
        $outsider = User::where('email', 'seller2.demo@apps.ipb.ac.id')->sole();
        $this->post($requestUrl, ['recipient_user_id' => $outsider->id, 'question' => self::DESCRIPTION])->assertForbidden();
        $this->post($requestUrl, ['recipient_user_id' => $seller->id, 'question' => 'Mohon kirim mutasi pembayaran privat.'])->assertRedirect();
        $clarification = $report->clarifications()->sole();
        self::assertTrue($clarification->due_at->equalTo($clarification->requested_at->addHours(24)));
        $notification = Notification::where('report_id', $report->id)->where('type', 'clarification.requested')->sole();
        self::assertSame($seller->id, $notification->user_id);
        self::assertSame(route('reports.clarifications.show', $clarification, false), $notification->destinationUrl());
        $this->post(route('admin.reports.decide', $report), $this->decision('restore_previous'))->assertConflict();

        $url = route('reports.clarifications.show', $clarification);
        $this->actingAs($order->buyer, 'web')->get($url)->assertForbidden();
        $this->post($url, ['response_text' => self::DESCRIPTION])->assertForbidden();
        $this->get(route('reports.show', $report))->assertOk()->assertDontSee($clarification->question);
        $this->travel(25)->hours();
        $this->actingAs($seller, 'web')->get($url)->assertOk()->assertSee('Tenggat sudah lewat');
        $this->post($url, ['response_text' => " \t "])->assertSessionHasErrors('response_text');
        $this->post($url, ['response_text' => str_repeat('x', 5001)])->assertSessionHasErrors('response_text');
        $this->post($url, ['attachment' => $this->png(), 'evidence_url' => 'https://example.test'])->assertSessionHasErrors('evidence_url');
        $this->post($url, ['response_text' => '  Mutasi privat terlampir.   ', 'attachment' => $this->png()])->assertRedirect($url);
        $this->post($url, ['evidence_url' => 'https://example.test/tambahan'])->assertRedirect($url);
        $response = $clarification->responses()->oldest('id')->firstOrFail();
        self::assertTrue($response->isLate());
        self::assertSame('Mutasi privat terlampir.', $response->response_text);
        self::assertSame(2, $clarification->responses()->count());
        self::assertSame(ReportStatus::Pending, $report->refresh()->status);
        $download = $this->fileUrl('clarification_evidence', $response->id);
        $this->get($download)->assertOk();
        $this->actingAs($order->buyer, 'web')->get($download)->assertForbidden();
        auth('web')->forgetUser();
        $this->actingAs($admin, 'admin')->get($download)->assertOk();
        $this->get(route('admin.reports.show', $report))->assertOk()->assertSee('Jawaban terlambat');
        $this->post(route('admin.reports.decide', $report), $this->decision('restore_previous'))->assertRedirect();
        $this->actingAs($seller, 'web')->get($url)->assertOk()->assertSee($response->response_text)->assertDontSee('Kirim Jawaban');
        $this->post($url, ['response_text' => 'Terlambat setelah putusan.'])->assertConflict();
        $this->assertSqlState('23514', fn () => $response->forceFill(['response_text' => 'Isi diubah'])->saveOrFail());
        $this->travelBack();
    }

    #[DataProvider('orderOutcomes')]
    public function test_mediation_outcomes_preserve_history_and_apply_correct_stage(
        int $fixture,
        string $code,
        OrderStatus $expected,
        int $advance,
        RefundStatus $refund,
        bool $verify,
        bool $archived,
    ): void {
        $order = $this->order($fixture);
        $before = $order->stage_version;
        $deadline = $order->delivery_deadline_at;
        $payment = $order->payment;
        $proof = $payment->proofs()->oldest('id')->firstOrFail();
        $sellerDecision = $proof->seller_review_status;
        $admin = Admin::firstOrFail();
        $report = app(ReportService::class)->submit($order->buyer, ReportType::OrderComplaint, $order->id, $this->input());
        $this->actingAs($admin, 'admin')->post(route('admin.reports.claim', $report))->assertRedirect();
        $decisionUrl = route('admin.reports.decide', $report);
        if ($verify) {
            $unrelated = $this->order(7)->payment->verified_proof_id;
            $this->post($decisionUrl, $this->decision($code) + ['verified_proof_id' => $unrelated])->assertSessionHasErrors('verified_proof_id');
            $this->post($decisionUrl, $this->decision($code) + [
                'verified_proof_id' => $proof->id, 'seller_evidence_reviewed' => true,
            ])->assertSessionHasErrors('seller_evidence_reviewed');
            self::assertSame(OrderStatus::Disputed, $order->refresh()->status);
            $clarifications = app(ClarificationService::class);
            $clarification = $clarifications->request($admin, $report, $order->seller_id, 'Mohon cocokkan bukti dengan mutasi Seller.');
            $clarifications->respond($order->seller, $clarification, ['response_text' => 'Mutasi Seller dan bukti pembayaran sesuai.']);
            if ($code === 'verify_payment_continue') {
                $this->post($decisionUrl, $this->decision($code) + [
                    'verified_proof_id' => $proof->id, 'seller_evidence_reviewed' => true,
                ])->assertSessionHasErrors('parties_ready');
            }
        }
        $extra = $verify ? ['verified_proof_id' => $proof->id, 'seller_evidence_reviewed' => true] : [];
        if ($code === 'verify_payment_continue') {
            $extra['parties_ready'] = true;
        }
        $this->post($decisionUrl, $this->decision($code) + $extra)->assertRedirectToRoute('admin.reports.show', $report);
        self::assertSame(ReportStatus::Resolved, $report->refresh()->status);
        self::assertSame($code, $report->decision_code);
        self::assertSame($expected, $order->refresh()->status);
        self::assertSame($before + $advance, $order->stage_version);
        self::assertSame($refund, $payment->refresh()->refund_status);
        self::assertSame($archived, $order->chatRoom->isArchived());
        self::assertSame($sellerDecision, $proof->refresh()->seller_review_status);

        if ($verify) {
            self::assertSame(PaymentStatus::Verified, $payment->status);
            self::assertSame($proof->id, $payment->verified_proof_id);
            self::assertSame($admin->id, $payment->verified_by_admin_id);
            self::assertTrue($order->delivery_deadline_at->equalTo($payment->verified_at->addHours(24 * $order->delivery_days_snapshot)));
        } elseif ($deadline !== null) {
            self::assertTrue($order->delivery_deadline_at->equalTo($deadline));
        }
        if ($refund === RefundStatus::Confirmed) {
            self::assertSame($admin->id, $payment->refund_confirmed_by_admin_id);
            self::assertNotNull($payment->refund_confirmed_at);
        }
        if ($fixture === 4) {
            self::assertSame(CancellationStatus::Voided, $order->cancellation_status);
        }
        $this->assertDatabaseHas('audit_logs', ['report_id' => $report->id, 'actor_admin_id' => $admin->id, 'action' => 'order.mediation_decided']);
        self::assertSame(2, $report->notifications()->where('type', 'report.decided')->count());
    }

    public static function orderOutcomes(): array
    {
        return [
            'restore' => [5, 'restore_previous', OrderStatus::Delivered, 0, RefundStatus::None, false, false],
            'verify and continue' => [3, 'verify_payment_continue', OrderStatus::InProgress, 1, RefundStatus::None, true, false],
            'verify and cancel' => [2, 'cancel_and_refund', OrderStatus::Cancelled, 1, RefundStatus::Pending, true, false],
            'cancel already paid' => [4, 'cancel_and_refund', OrderStatus::Cancelled, 1, RefundStatus::Pending, false, false],
            'confirm refund' => [8, 'confirm_refund', OrderStatus::Cancelled, 0, RefundStatus::Confirmed, false, true],
            'complete delivered' => [16, 'complete_delivery', OrderStatus::Completed, 1, RefundStatus::None, false, true],
        ];
    }

    public function test_seeded_overdue_clarification_links_and_decides_without_an_automatic_verdict(): void
    {
        $clarification = ReportClarification::query()->where('due_at', '<', now())->whereHas('report', fn ($query) => $query->where('status', ReportStatus::Pending))->whereDoesntHave('responses')->firstOrFail();
        $this->actingAs($clarification->recipient, 'web')->get(route('reports.index'))->assertOk()->assertSee('Tenggat terlewat');
        $this->actingAs($clarification->requestedByAdmin, 'admin')->post(route('admin.reports.decide', $clarification->report),
            $this->decision('dismiss_restore', 'dismissed'))->assertRedirect();
        self::assertSame(ReportStatus::Dismissed, $clarification->report->refresh()->status);
        self::assertSame(PaymentStatus::Rejected, $clarification->report->order->payment->status);
        self::assertSame(RefundStatus::None, $clarification->report->order->payment->refund_status);
    }

    private function input(array $extra = []): array
    {
        return $extra + ['category' => 'transaction_issue', 'description' => self::DESCRIPTION];
    }

    private function decision(string $code, string $status = 'resolved'): array
    {
        return ['status' => $status, 'decision_code' => $code, 'decision_note' => 'Riwayat dan bukti kasus demo sudah diperiksa.'];
    }

    private function order(int $fixture): Order
    {
        return Order::where('creation_key', sprintf('00000000-0000-4000-8000-%012d', $fixture))->sole();
    }

    private function submitUrl(ReportType $type, int $id): string
    {
        return route('reports.store', ['type' => $type->value, 'target' => $id]);
    }

    private function fileUrl(string $kind, int $id): string
    {
        return route('files.show', ['jenis' => $kind, 'id' => $id]);
    }

    private function png(): UploadedFile
    {
        return new UploadedFile(resource_path('demo-fixtures/proof-bank.png'), 'bukti-demo.png', test: true);
    }
}
