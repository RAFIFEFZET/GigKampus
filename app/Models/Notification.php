<?php

namespace App\Models;

use App\Events\NotificationChanged;
use App\Models\Concerns\HasPreciseTimestamps;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasPreciseTimestamps;

    public const UPDATED_AT = null;

    protected $table = 'notifications';

    protected $attributes = [
        'data' => '{}',
    ];

    protected static function booted(): void
    {
        static::created(function (Notification $notification): void {
            NotificationChanged::dispatch(
                $notification->user_id === null ? 'admin' : 'user',
                $notification->user_id ?? $notification->admin_id,
                $notification->id,
            );
        });
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'admin_id' => 'integer',
            'order_id' => 'integer',
            'report_id' => 'integer',
            'data' => 'object',
            'created_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function destinationUrl(): ?string
    {
        $url = $this->data->relative_url ?? null;
        if (is_string($url) && self::isSafeRelativeUrl($url)) {
            return $url;
        }

        $requestId = self::navigationId($this->data->service_request_id ?? null);
        $verificationId = self::navigationId($this->data->verification_id ?? null);

        return match (true) {
            $this->admin_id !== null && $this->report_id !== null => route('admin.reports.show', $this->report_id, false),
            $this->user_id !== null && $this->report_id !== null && str_starts_with($this->type, 'clarification.') => route('reports.index', $this->order_id === null ? [] : ['order' => $this->order_id], false),
            $this->user_id !== null && $this->report_id !== null && str_starts_with($this->type, 'report.') => route('reports.show', $this->report_id, false),
            $this->order_id !== null => route('orders.show', $this->order_id, false),
            $this->user_id !== null && $requestId !== false => route('requests.show', $requestId, false),
            $this->user_id !== null && str_starts_with($this->type, 'ktm.') => route('ktm.index', absolute: false),
            $this->admin_id !== null && $verificationId !== false && str_starts_with($this->type, 'ktm.') => route('admin.ktm.show', $verificationId, false),
            default => null,
        };
    }

    private static function navigationId(mixed $value): int|false
    {
        return is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
    }

    private static function isSafeRelativeUrl(string $url): bool
    {
        if (! str_starts_with($url, '/') || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return false;
        }

        // Check every decoded form for an authority prefix, backslashes, or control characters.
        do {
            if (str_starts_with($url, '//') || str_contains($url, '\\') || preg_match('/[\x00-\x1f\x7f]/', $url)) {
                return false;
            }

            $previous = $url;
            $url = rawurldecode($url);
        } while ($url !== $previous);

        return true;
    }

    public function markReadBy(User|Admin $recipient, ?CarbonInterface $at = null): void
    {
        $recipientId = $recipient instanceof User ? $this->user_id : $this->admin_id;

        if (! $recipient->hasId($recipientId)) {
            throw new DomainException('Only the recipient can mark this notification as read.');
        }

        $this->read_at ??= $at ?? now();
    }
}
