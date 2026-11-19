<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\ReportCategory;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Models\Concerns\HasPreciseTimestamps;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    use HasPreciseTimestamps;

    public const UPDATED_AT = null;

    protected $table = 'reports';

    protected $attributes = [
        'status' => ReportStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'reporter_id' => 'integer',
            'report_type' => ReportType::class,
            'gig_id' => 'integer',
            'service_request_id' => 'integer',
            'review_id' => 'integer',
            'chat_room_id' => 'integer',
            'order_id' => 'integer',
            'category' => ReportCategory::class,
            'file_size' => 'integer',
            'status' => ReportStatus::class,
            'handled_by_admin_id' => 'integer',
            'order_status_before_dispute' => OrderStatus::class,
            'order_stage_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function gig(): BelongsTo
    {
        return $this->belongsTo(Gig::class, 'gig_id');
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class, 'review_id');
    }

    public function chatRoom(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'chat_room_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function handledByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'handled_by_admin_id');
    }

    public function clarifications(): HasMany
    {
        return $this->hasMany(ReportClarification::class, 'report_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'report_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'report_id');
    }

    public function isHandledBy(Admin $admin): bool
    {
        return $this->status === ReportStatus::Pending && $admin->hasId($this->handled_by_admin_id);
    }

    public function canResolveOrderDispute(Admin $admin, Order $order): bool
    {
        return $this->exists
            && $this->report_type === ReportType::OrderComplaint
            && $this->isHandledBy($admin)
            && $order->status === OrderStatus::Disputed
            && $this->order_id !== null
            && $this->order_id === $order->getKey()
            && $this->order_stage_version === $order->stage_version;
    }

    public function claim(Admin $admin): void
    {
        if ($this->status !== ReportStatus::Pending || $this->handled_by_admin_id !== null || $admin->getKey() === null) {
            throw new DomainException('Only an unclaimed pending report can be claimed.');
        }

        $this->handled_by_admin_id = $admin->getKey();
    }

    public function decide(
        Admin $admin,
        ReportStatus $decision,
        string $code,
        string $note,
        ?CarbonInterface $at = null,
    ): void {
        $code = trim($code);
        $note = trim($note);

        if (! $this->isHandledBy($admin) || $decision === ReportStatus::Pending
            || $code === '' || mb_strlen($code) > 64 || mb_strlen($note) < 5 || mb_strlen($note) > 5000) {
            throw new DomainException('The assigned admin must record a final decision and its reason.');
        }

        $this->status = $decision;
        $this->decision_code = $code;
        $this->decision_note = $note;
        $this->decided_at = $at ?? now();
    }
}
