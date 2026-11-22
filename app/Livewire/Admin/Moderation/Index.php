<?php

namespace App\Livewire\Admin\Moderation;

use App\Enums\GigStatus;
use App\Enums\ReportStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Gig;
use App\Models\Report;
use App\Models\Review;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ModerationService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public const SECTIONS = [
        'gig' => 'Gig',
        'request' => 'Permintaan Jasa',
        'review' => 'Ulasan',
        'account' => 'Akun Mahasiswa',
    ];

    private const STATUS_FILTERS = [
        'gig' => ['all' => 'Semua', 'active' => 'Aktif', 'inactive' => 'Nonaktif', 'suspended' => 'Disuspend'],
        'request' => ['all' => 'Semua', 'open' => 'Terbuka', 'ordered' => 'Dipesan', 'closed' => 'Ditutup', 'hidden' => 'Disembunyikan'],
        'review' => ['all' => 'Semua', 'visible' => 'Tayang', 'hidden' => 'Disembunyikan'],
        'account' => ['all' => 'Semua', 'active' => 'Aktif', 'suspended' => 'Disuspend'],
    ];

    #[Url(as: 'jenis')]
    public string $section = 'gig';

    #[Url]
    public string $status = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    #[Locked]
    public ?int $targetId = null;

    public string $action = '';

    public string $reason = '';

    #[Locked]
    public bool $saved = false;

    public function updatedSection(): void
    {
        $this->status = 'all';
        $this->updatedSearch();
    }

    public function updatedStatus(): void
    {
        $this->updatedSearch();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->closeAction();
        $this->saved = false;
    }

    public function openAction(int $id, string $action): void
    {
        $this->admin();
        $item = $this->items()->findOrFail($id);
        $this->closeAction();
        $this->saved = false;
        $this->authorizeAction($item, $action);
        $this->action = $action;
        $this->targetId = $id;
    }

    public function closeAction(): void
    {
        $this->reset('targetId', 'action', 'reason');
        $this->resetValidation();
    }

    public function apply(ModerationService $moderation): void
    {
        $admin = $this->admin();
        abort_if($this->targetId === null, 404);
        $item = $this->items()->findOrFail($this->targetId);
        $this->authorizeAction($item, $this->action);

        $moderation->apply($admin, $this->action, $item->id, $this->reason);
        $this->closeAction();
        $this->saved = true;
    }

    public function render(): View
    {
        $admin = $this->admin();
        $query = $this->listing($admin);
        $statusOptions = self::STATUS_FILTERS[$this->section];
        $activeStatus = array_key_exists($this->status, $statusOptions) ? $this->status : 'all';
        if ($activeStatus !== 'all') {
            $this->filterStatus($query, $activeStatus);
        }
        $keyword = mb_substr(trim($this->search), 0, 150);
        if ($keyword !== '') {
            $pattern = '%'.addcslashes($keyword, '\\%_').'%';
            $column = match ($this->section) {
                'review' => 'comment',
                'account' => 'name',
                default => 'title',
            };
            $query->where(function (Builder $query) use ($keyword, $column, $pattern): void {
                $query->where($column, 'ilike', $pattern);
                $id = filter_var($keyword, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id !== false) {
                    $query->orWhereKey($id);
                }
            });
        }

        $items = $query->orderByDesc('id')->paginate(12)->appends([
            'jenis' => $this->section, 'status' => $activeStatus, 'q' => $keyword,
        ]);
        $reasons = AuditLog::query()->where('entity_type', $query->getModel()->getTable())
            ->whereIn('entity_id', $items->getCollection()->modelKeys())
            ->whereNotNull('actor_admin_id')->whereNotNull('reason')
            ->selectRaw('DISTINCT ON (entity_id) entity_id, reason, occurred_at')
            ->orderBy('entity_id')->orderByDesc('occurred_at')->orderByDesc('id')->get()->keyBy('entity_id');
        $counts = [
            'gig' => Gig::query()->count(),
            'request' => ServiceRequest::query()->count(),
            'review' => Review::query()->count(),
            'account' => User::query()->count(),
        ];

        return view('livewire.admin.moderation.index', compact('activeStatus', 'counts', 'items', 'reasons', 'statusOptions'));
    }

    public static function actions(Model $item): array
    {
        $available = match (true) {
            $item instanceof Gig => [$item->status === GigStatus::Suspended ? 'unsuspend_gig' : 'suspend_gig'],
            $item instanceof ServiceRequest => array_filter([
                $item->status === ServiceRequestStatus::Open ? 'close_request' : null,
                $item->is_hidden ? null : 'hide_request',
            ]),
            $item instanceof Review => [],
            $item instanceof User => [$item->is_suspended ? 'unsuspend_user' : 'suspend_user'],
        };

        return array_intersect_key(ModerationService::ACTION_LABELS, array_flip($available));
    }

    private function authorizeAction(Model $item, string $action): void
    {
        if (! array_key_exists($action, self::actions($item))) {
            throw ValidationException::withMessages(['action' => 'Pilih tindakan yang tersedia untuk item ini.']);
        }
    }

    private function admin(): Admin
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    private function items(): Builder
    {
        return match ($this->section) {
            'gig' => Gig::query(),
            'request' => ServiceRequest::query(),
            'review' => Review::query(),
            'account' => User::query(),
            default => abort(404),
        };
    }

    private function listing(Admin $admin): Builder
    {
        $query = $this->items();

        return match ($this->section) {
            'gig' => $query->with(['seller:id,name', 'category:id,name'])->withCount('reports'),
            'request' => $query->with(['buyer:id,name', 'category:id,name'])->withCount('reports'),
            'review' => $query->with([
                'reviewer:id,name', 'seller:id,name', 'order:id,category_name_snapshot',
                'reports' => fn ($reports) => $reports->where('status', ReportStatus::Pending)
                    ->where(fn ($query) => $query->whereNull('handled_by_admin_id')->orWhere('handled_by_admin_id', $admin->id))
                    ->orderByRaw('handled_by_admin_id IS NULL')->orderBy('id'),
            ])->withCount('reports'),
            'account' => $query->addSelect(['reports_count' => Report::query()->selectRaw('count(*)')->where(function (Builder $reports): void {
                $reports->whereColumn('reporter_id', 'users.id')
                    ->orWhereHas('gig', fn (Builder $gig) => $gig->whereColumn('seller_id', 'users.id'))
                    ->orWhereHas('serviceRequest', fn (Builder $request) => $request->whereColumn('buyer_id', 'users.id'))
                    ->orWhereHas('review', fn (Builder $review) => $review->whereColumn('reviewer_id', 'users.id')->orWhereColumn('seller_id', 'users.id'))
                    ->orWhereHas('chatRoom', fn (Builder $room) => $room->whereColumn('buyer_id', 'users.id')->orWhereColumn('seller_id', 'users.id'))
                    ->orWhereHas('order', fn (Builder $order) => $order->whereColumn('buyer_id', 'users.id')->orWhereColumn('seller_id', 'users.id'));
            })]),
        };
    }

    private function filterStatus(Builder $query, string $status): void
    {
        match ($this->section) {
            'gig' => $query->where('status', $status),
            'request' => $status === 'hidden'
                ? $query->where('is_hidden', true)
                : $query->where('is_hidden', false)->where('status', $status),
            'review' => $query->where('is_hidden', $status === 'hidden'),
            'account' => $query->where('is_suspended', $status === 'suspended'),
        };
    }
}
