<?php

namespace App\Services;

use App\Enums\GigStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Admin;
use App\Models\Gig;
use App\Models\Notification;
use App\Models\Report;
use App\Models\Review;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ModerationService
{
    public const ACTION_LABELS = [
        'suspend_gig' => 'Suspend Gig',
        'unsuspend_gig' => 'Cabut Suspend Gig',
        'close_request' => 'Tutup Permintaan',
        'hide_request' => 'Sembunyikan Permintaan',
        'hide_review' => 'Sembunyikan Ulasan',
        'suspend_user' => 'Suspend Akun',
        'unsuspend_user' => 'Cabut Suspend Akun',
    ];

    public function __construct(private readonly RequestBoard $requests) {}

    public function apply(Admin $admin, string $action, int $targetId, string $reason, ?Report $report = null): void
    {
        $reason = Str::trim($reason);
        $minimum = $action === 'hide_review' ? 5 : 1;
        Validator::make(compact('action', 'reason'), [
            'action' => ['required', Rule::in(array_keys(self::ACTION_LABELS))],
            'reason' => ['required', 'string', 'between:'.$minimum.',1000', 'not_regex:/\x00/'],
        ], [
            'reason.required' => 'Isi alasan tindakan moderasi.',
            'reason.between' => "Alasan moderasi harus {$minimum}–1.000 karakter.",
        ])->validate();

        DB::transaction(function () use ($admin, $action, $targetId, $reason, $report): void {
            if ($report !== null) {
                $report = Report::query()->lockForUpdate()->findOrFail($report->id);
                abort_unless($report->isHandledBy($admin), 403);
            }
            abort_if($action === 'hide_review' && $report?->review_id !== $targetId, 403);

            match ($action) {
                'suspend_gig', 'unsuspend_gig' => $this->moderateGig($admin, $targetId, $action === 'suspend_gig', $reason, $report),
                'close_request', 'hide_request' => $this->moderateRequest($admin, $targetId, $action === 'close_request', $reason, $report),
                'hide_review' => $this->hideReview($admin, $targetId, $reason, $report),
                'suspend_user', 'unsuspend_user' => $this->moderateAccount($admin, $targetId, $action === 'suspend_user', $reason, $report),
            };
        }, 3);
    }

    private function moderateGig(Admin $admin, int $id, bool $suspend, string $reason, ?Report $report): void
    {
        $gig = Gig::query()->lockForUpdate()->findOrFail($id);
        $this->requireChange(($gig->status === GigStatus::Suspended) !== $suspend);
        $before = $gig->only(['status', 'version']);
        if ($suspend) {
            $gig->suspend();
        } else {
            $gig->liftSuspension();
        }
        $gig->save();

        $action = $suspend ? 'gig.suspended' : 'gig.unsuspended';
        $title = $suspend ? 'Gig disuspend' : 'Suspend Gig dicabut';
        Audit::record($admin, $action, $gig, $before, $gig->only(['status', 'version']), $reason, $report);
        $this->notifyOwner($gig->seller_id, $action, $title.' · '.$gig->title, $reason, route('studio.gigs.index', absolute: false));
    }

    private function moderateRequest(Admin $admin, int $id, bool $close, string $reason, ?Report $report): void
    {
        $request = ServiceRequest::query()->lockForUpdate()->findOrFail($id);
        $close = $close && $request->status !== ServiceRequestStatus::Ordered;
        $before = $request->only(['status', 'is_hidden']);
        if ($close) {
            $this->requireChange($request->status === ServiceRequestStatus::Open);
            $request->close();
        } else {
            $this->requireChange(! $request->is_hidden);
            $request->is_hidden = true;
        }
        $request->save();

        $action = $close ? 'request.closed' : 'request.hidden';
        $title = $close ? 'Permintaan ditutup' : 'Permintaan disembunyikan';
        Audit::record($admin, $action, $request, $before, $request->only(['status', 'is_hidden']), $reason, $report);
        if ($close) {
            $this->requests->rejectPendingOffers($request, $admin, $reason, $report);
        }
        $this->notifyOwner($request->buyer_id, $action, $title.' · '.$request->title, $reason, route('requests.show', $request, false));
    }

    private function hideReview(Admin $admin, int $id, string $reason, ?Report $report): void
    {
        $review = Review::query()->lockForUpdate()->findOrFail($id);
        $this->requireChange(! $review->is_hidden);
        $review->hide($admin, $reason);
        $review->save();

        Audit::record($admin, 'review.hidden', $review, ['is_hidden' => false], ['is_hidden' => true], $reason, $report);
        $url = route('orders.show', $review->order_id, false);
        foreach ([$review->reviewer_id, $review->seller_id] as $participant) {
            $this->notifyOwner($participant, 'review.hidden', 'Ulasan pesanan disembunyikan', $reason, $url);
        }
    }

    private function moderateAccount(Admin $admin, int $id, bool $suspend, string $reason, ?Report $report): void
    {
        $student = User::query()->lock('for no key update')->findOrFail($id);
        $this->requireChange($student->is_suspended !== $suspend);
        $student->forceFill(['is_suspended' => $suspend])->save();

        $action = $suspend ? 'account.suspended' : 'account.unsuspended';
        $title = $suspend ? 'Akun disuspend' : 'Suspend akun dicabut';
        Audit::record($admin, $action, $student, ['is_suspended' => ! $suspend], ['is_suspended' => $suspend], $reason, $report);
        $this->notifyOwner($student->id, $action, $title, $reason);
    }

    private function notifyOwner(int $recipient, string $type, string $title, string $reason, ?string $url = null): void
    {
        Notification::query()->forceCreate([
            'user_id' => $recipient,
            'type' => $type,
            'title' => Str::limit($title, 150, ''),
            'body' => $reason,
            'data' => ['relative_url' => $url],
        ]);
    }

    private function requireChange(bool $available): void
    {
        if (! $available) {
            throw ValidationException::withMessages(['action' => 'Tindakan ini sudah tidak tersedia untuk status saat ini.']);
        }
    }
}
