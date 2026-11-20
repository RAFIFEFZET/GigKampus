<?php

namespace App\Services;

use App\Enums\GigStatus;
use App\Enums\OrderStatus;
use App\Enums\ReportStatus;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Report;
use App\Services\Orders\OrderActivity;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class MediationDecisionService
{
    public const ORDER_OUTCOMES = [
        'restore_previous' => 'Pulihkan tahap sebelumnya',
        'verify_payment_continue' => 'Sahkan pembayaran dan lanjutkan pekerjaan',
        'cancel_and_refund' => 'Hentikan pesanan dan wajibkan pengembalian dana',
        'confirm_refund' => 'Tetapkan pengembalian dana telah diterima',
        'complete_delivery' => 'Tetapkan hasil sesuai dan selesaikan pesanan',
    ];

    public static function outcomes(Report $report): array
    {
        if ($report->order_id === null) {
            return ['content_resolved' => 'Selesaikan peninjauan laporan'];
        }

        return array_intersect_key(self::ORDER_OUTCOMES, array_flip(match ($report->order_status_before_dispute) {
            OrderStatus::AwaitingPayment, OrderStatus::AwaitingValidation => ['restore_previous', 'verify_payment_continue', 'cancel_and_refund'],
            OrderStatus::Cancelled => ['restore_previous', 'confirm_refund'],
            OrderStatus::Delivered => ['restore_previous', 'cancel_and_refund', 'complete_delivery'],
            default => ['restore_previous', 'cancel_and_refund'],
        }));
    }

    public static function moderationOptions(Report $report): array
    {
        $gig = $report->gig ?? $report->order?->gig ?? $report->chatRoom?->gig;
        $request = $report->serviceRequest ?? $report->order?->priceOffer?->serviceRequest
            ?? $report->chatRoom?->priceOffer?->serviceRequest;
        $actions = [];

        if ($gig !== null && $gig->status !== GigStatus::Suspended) {
            $actions['suspend_gig:'.$gig->id] = 'Suspend Gig '.$gig->title;
        }
        if ($request !== null && ! $request->is_hidden) {
            $actions['hide_request:'.$request->id] = 'Sembunyikan permintaan '.$request->title;
        }
        if ($report->review !== null && ! $report->review->is_hidden) {
            $actions['hide_review:'.$report->review->id] = 'Sembunyikan ulasan';
        }
        foreach (ReportService::parties($report) as $party) {
            if (! $party->is_suspended) {
                $actions['suspend_user:'.$party->id] = 'Suspend akun '.$party->name;
            }
        }

        return $actions;
    }

    public function decide(Admin $admin, Report $report, array $input): Report
    {
        foreach (['decision_note', 'moderation_reason'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::trim($input[$field]);
            }
        }
        $input = Validator::make($input, [
            'status' => ['required', Rule::in([ReportStatus::Resolved->value, ReportStatus::Dismissed->value])],
            'decision_code' => ['required', 'string', 'max:64'],
            'decision_note' => ['required', 'string', 'min:5', 'max:5000', 'not_regex:/\x00/'],
            'verified_proof_id' => ['nullable', 'integer', 'min:1'],
            'seller_evidence_reviewed' => ['sometimes', 'accepted'],
            'parties_ready' => ['sometimes', 'accepted'],
            'moderation_action' => ['nullable', 'string'],
            'moderation_reason' => ['nullable', 'required_with:moderation_action', 'string', 'min:5', 'max:1000', 'not_regex:/\x00/'],
        ])->validate();

        try {
            return DB::transaction(function () use ($admin, $report, $input): Report {
                $report = Report::query()->lockForUpdate()->findOrFail($report->id);
                abort_unless($report->isHandledBy($admin), 403);
                abort_if($report->clarifications()->where('due_at', '>', now())->whereDoesntHave('responses')->exists(),
                    409, 'Tunggu jawaban klarifikasi atau sampai tenggatnya terlewat sebelum menetapkan putusan.');
                $status = ReportStatus::from($input['status']);
                $code = $input['decision_code'];
                $note = $input['decision_note'];
                $proofId = $input['verified_proof_id'] ?? null;
                $allowed = $status === ReportStatus::Dismissed
                    ? [$report->order_id === null ? 'dismiss_report' : 'dismiss_restore' => true]
                    : self::outcomes($report);
                if (! array_key_exists($code, $allowed)) {
                    throw ValidationException::withMessages(['decision_code' => 'Pilih hasil yang tersedia untuk tahap kasus ini.']);
                }
                $at = now();

                if ($report->order_id !== null) {
                    $order = Order::query()->lockForUpdate()->findOrFail($report->order_id);
                    $payment = $order->payment()->lockForUpdate()->firstOrFail();
                    $before = $this->state($order, $payment);
                    $outcome = $report->order_status_before_dispute;

                    $requiresProof = $code === 'verify_payment_continue' || ($code === 'cancel_and_refund' && ! $payment->isVerified());
                    if ($requiresProof && ($proofId === null || ($proof = $payment->proofs()->lockForUpdate()->find($proofId)) === null)) {
                        throw ValidationException::withMessages(['verified_proof_id' => 'Pilih bukti pembayaran milik pesanan ini.']);
                    }
                    if (! $requiresProof && $proofId !== null) {
                        throw ValidationException::withMessages(['verified_proof_id' => 'Hasil ini tidak memerlukan pemilihan bukti bayar.']);
                    }
                    if ($requiresProof) {
                        $this->assertPaymentReview($report, $order, $input, $code === 'verify_payment_continue');
                        $payment->verifyByAdmin($proof, $admin, $order, $report, $at);
                    }
                    switch ($code) {
                        case 'verify_payment_continue':
                            $outcome = OrderStatus::InProgress;
                            break;
                        case 'cancel_and_refund':
                            $payment->requestRefund($order, $at);
                            $outcome = OrderStatus::Cancelled;
                            break;
                        case 'confirm_refund':
                            $payment->confirmRefundByAdmin($admin, $order, $report, $at);
                            break;
                        case 'complete_delivery':
                            $outcome = OrderStatus::Completed;
                            break;
                    }
                    $order->resolveDispute($report, $admin, $outcome, $at, $payment);
                    $payment->saveOrFail();
                    $order->saveOrFail();
                    if ($order->isFinal($payment)) {
                        OrderActivity::archiveChat($order);
                    }
                    $after = $this->state($order, $payment);
                    if ($requiresProof) {
                        $after['seller_evidence_reviewed'] = true;
                        if ($code === 'verify_payment_continue') {
                            $after['parties_ready'] = true;
                        }
                    }
                    Audit::record($admin, 'order.mediation_decided', $order, $before, $after, $note, $report);
                } elseif ($proofId !== null) {
                    throw ValidationException::withMessages(['verified_proof_id' => 'Laporan ini tidak memiliki bukti pembayaran pesanan.']);
                }

                if (($action = $input['moderation_action'] ?? null) !== null) {
                    if (! array_key_exists($action, self::moderationOptions($report))) {
                        throw ValidationException::withMessages(['moderation_action' => 'Pilih tindakan untuk pihak atau konten yang terkait.']);
                    }
                    [$type, $id] = explode(':', $action);
                    app(ModerationService::class)->apply($admin, $type, (int) $id, $input['moderation_reason'], $report);
                }

                $report->decide($admin, $status, $code, $note, $at);
                $report->saveOrFail();
                Audit::record($admin, 'report.decided', $report, ['status' => ReportStatus::Pending->value], [
                    'status' => $status->value, 'decision_code' => $code,
                ], $note);

                $recipients = $report->order_id !== null ? ReportService::parties($report) : collect([$report->reporter]);
                foreach ($recipients as $recipient) {
                    ReportService::notify($report, $recipient, 'report.decided', 'Putusan laporan tersedia',
                        'Admin telah menetapkan putusan akhir.', route('reports.show', $report, false));
                }

                return $report;
            }, 3);
        } catch (DomainException $exception) {
            throw new ConflictHttpException('Putusan ini tidak berlaku pada tahap atau kondisi pembayaran tersebut.', $exception);
        }
    }

    private function assertPaymentReview(Report $report, Order $order, array $input, bool $continues): void
    {
        $sellerClarification = $report->clarifications()->where('recipient_user_id', $order->seller_id)->exists();
        $sellerAttachment = $order->chatRoom?->messages()->where('sender_id', $order->seller_id)
            ->where(fn ($messages) => $messages->whereNotNull('file_path')->orWhereNotNull('link_url'))->exists();

        if (! isset($input['seller_evidence_reviewed']) || (! $sellerClarification && ! $sellerAttachment)) {
            throw ValidationException::withMessages([
                'seller_evidence_reviewed' => 'Periksa bukti dari Seller di chat atau minta klarifikasi Seller. Catat dasar pemeriksaan dan konfirmasikan peninjauan.',
            ]);
        }
        if ($continues && ! isset($input['parties_ready'])) {
            throw ValidationException::withMessages(['parties_ready' => 'Konfirmasikan bahwa kedua pihak siap melanjutkan pesanan.']);
        }
    }

    private function state(Order $order, Payment $payment): array
    {
        return [
            'status' => $order->status->value, 'stage_version' => $order->stage_version,
            'cancellation_status' => $order->cancellation_status->value,
            'delivery_deadline_at' => $order->delivery_deadline_at?->toIso8601String(),
            'payment_status' => $payment->status->value,
            'verified_proof_id' => $payment->verified_proof_id, 'verified_by_admin_id' => $payment->verified_by_admin_id,
            'refund_status' => $payment->refund_status->value, 'refund_confirmed_by_admin_id' => $payment->refund_confirmed_by_admin_id,
            'refund_confirmed_at' => $payment->refund_confirmed_at?->toIso8601String(),
        ];
    }
}
