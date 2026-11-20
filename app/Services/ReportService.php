<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ReportCategory;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Models\Admin;
use App\Models\ChatRoom;
use App\Models\Gig;
use App\Models\Order;
use App\Models\Report;
use App\Models\Review;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Policies\ReportPolicy;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ReportService
{
    public function __construct(private readonly ReportPolicy $policy) {}

    public static function target(ReportType $type, int $id, bool $lock = false): Gig|ServiceRequest|Review|ChatRoom|Order
    {
        $model = match ($type) {
            ReportType::OrderComplaint => Order::class,
            ReportType::GigReport => Gig::class,
            ReportType::RequestReport => ServiceRequest::class,
            ReportType::ReviewReport => Review::class,
            ReportType::ChatReport => ChatRoom::class,
        };

        // A non-key lock serializes submissions without interfering with foreign key checks.
        return $model::query()->when($lock, fn ($query) => $query->lock('for no key update'))->findOrFail($id);
    }

    public function submit(User $user, ReportType $type, int $id, array $input): Report
    {
        if (is_string($input['description'] ?? null)) {
            $input['description'] = Str::trim($input['description']);
        }
        $input = Validator::make($input, [
            'category' => ['required', Rule::enum(ReportCategory::class)],
            'description' => ['required', 'string', 'min:20', 'max:5000', 'not_regex:/\x00/'],
            ...ReportEvidence::rules(),
        ], [
            'category.required' => 'Pilih kategori laporan.',
            'description.min' => 'Uraian minimal 20 karakter setelah spasi di awal dan akhir dihapus.',
            'description.max' => 'Uraian maksimal 5.000 karakter.',
            'attachment.max' => 'Berkas maksimal 20 MB.',
            'evidence_url.url' => 'Gunakan tautan HTTPS yang valid.',
            'evidence_url.prohibits' => 'Pilih satu berkas atau satu tautan HTTPS.',
        ])->validate();

        return ReportEvidence::transaction("reports/{$type->value}/{$id}", $input,
            function (Closure $evidence, ?Report &$record) use ($user, $type, $id, $input): Report {
                $target = self::target($type, $id, lock: true);
                abort_unless($this->policy->submit($user, $target), 403);
                $column = $type->targetColumn();
                $snapshot = [];

                if ($target instanceof Order) {
                    $payment = $target->payment()->lockForUpdate()->firstOrFail();
                    $target->setRelation('payment', $payment);
                    $reason = $this->policy->complaintUnavailable($user, $target);
                    abort_if($reason !== null, 409, $reason ?? '');
                    $snapshot = ['order_status_before_dispute' => $target->status, 'order_stage_version' => $target->stage_version];
                    $target->openDispute($user, $payment, $target->status === OrderStatus::AwaitingPayment && $payment->hasRejectedProofs());
                    $target->saveOrFail();
                } else {
                    abort_if(Report::where($column, $id)->where('reporter_id', $user->id)
                        ->where('status', ReportStatus::Pending)->exists(), 409, 'Anda sudah memiliki laporan aktif untuk target ini.');
                }

                $record = Report::query()->forceCreate([
                    $column => $id, 'report_type' => $type, 'reporter_id' => $user->id,
                    'category' => $input['category'], 'description' => $input['description'],
                    ...$snapshot, ...$evidence(),
                ]);
                Audit::record($user, 'report.opened', $record, [], [
                    'report_type' => $type->value, 'status' => ReportStatus::Pending->value,
                    'target_id' => $id, ...$snapshot,
                ]);
                Admin::query()->eachById(fn (Admin $admin) => self::notify($record, $admin, 'report.opened',
                    'Laporan baru', 'Laporan baru menunggu peninjauan.', route('admin.reports.show', $record, false)));
                if ($target instanceof Order) {
                    Audit::record($user, 'order.disputed', $target, ['status' => $snapshot['order_status_before_dispute']->value],
                        ['status' => $target->status->value, 'stage_version' => $target->stage_version], report: $record);
                    self::notify($record, $target->hasBuyer($user) ? $target->seller : $target->buyer, 'order.disputed',
                        'Pesanan dalam sengketa', 'Tindakan transaksi dijeda selama komplain diperiksa.', route('orders.show', $target, false));
                }

                return $record;
            });
    }

    public function claim(Admin $admin, Report $report): Report
    {
        return DB::transaction(function () use ($admin, $report): Report {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);
            abort_if($report->status !== ReportStatus::Pending || $report->handled_by_admin_id !== null,
                409, 'Kasus ini sudah diambil atau diputus.');
            $report->claim($admin);
            $report->saveOrFail();
            Audit::record($admin, 'report.claimed', $report, [], ['handled_by_admin_id' => $admin->id]);

            return $report;
        });
    }

    /** @return Collection<int, User> */
    public static function parties(Report $report): Collection
    {
        $targetUsers = match ($report->report_type) {
            ReportType::OrderComplaint => [$report->order->buyer_id, $report->order->seller_id],
            ReportType::GigReport => [$report->gig->seller_id],
            ReportType::RequestReport => [$report->serviceRequest->buyer_id],
            ReportType::ReviewReport => [$report->review->reviewer_id, $report->review->seller_id],
            ReportType::ChatReport => [$report->chatRoom->buyer_id, $report->chatRoom->seller_id],
        };

        return User::query()->whereKey([$report->reporter_id, ...$targetUsers])->orderBy('name')->get();
    }

    public static function notify(Report $report, Admin|User $recipient, string $type, string $title, string $body, string $url): void
    {
        $recipient->notifications()->forceCreate([
            'report_id' => $report->id, 'order_id' => $report->order_id,
            'type' => $type, 'title' => $title, 'body' => $body, 'data' => ['relative_url' => $url],
        ]);
    }
}
