<?php

namespace App\Livewire\Admin\Moderation;

use App\Enums\GigStatus;
use App\Enums\ReportStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Gig;
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

    #[Url(as: 'jenis')]
    public string $section = 'gig';

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
        $this->resetPage();
        $this->closeAction();
        $this->saved = false;
    }

    public function updatedSearch(): void
    {
        $this->updatedSection();
    }

    public function openAction(int $id): void
    {
        $this->admin();
        $item = $this->items()->findOrFail($id);
        $this->closeAction();
        $this->saved = false;
        $this->action = array_key_first(self::actions($item)) ?? '';
        $this->targetId = $this->action === '' ? null : $id;
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
        if (! array_key_exists($this->action, self::actions($item))) {
            throw ValidationException::withMessages(['action' => 'Pilih tindakan yang tersedia untuk item ini.']);
        }

        $moderation->apply($admin, $this->action, $item->id, $this->reason);
        $this->closeAction();
        $this->saved = true;
    }

    public function render(): View
    {
        $this->admin();
        $query = $this->items();
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

        $items = $query->orderByDesc('id')->paginate(12)->appends(['jenis' => $this->section, 'q' => $keyword]);
        $reasons = AuditLog::query()->where('entity_type', $query->getModel()->getTable())
            ->whereIn('entity_id', $items->getCollection()->modelKeys())
            ->whereNotNull('actor_admin_id')->whereNotNull('reason')
            ->selectRaw('DISTINCT ON (entity_id) entity_id, reason')
            ->orderBy('entity_id')->orderByDesc('occurred_at')->orderByDesc('id')->get()->keyBy('entity_id');

        return view('livewire.admin.moderation.index', compact('items', 'reasons'));
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

    private function admin(): Admin
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    private function items(): Builder
    {
        return match ($this->section) {
            'gig' => Gig::query()->with('seller:id,name'),
            'request' => ServiceRequest::query()->with('buyer:id,name'),
            'review' => Review::query()->with([
                'reviewer:id,name', 'seller:id,name',
                'reports' => fn ($reports) => $reports->where('status', ReportStatus::Pending)
                    ->where(fn ($query) => $query->whereNull('handled_by_admin_id')->orWhere('handled_by_admin_id', $this->admin()->id))
                    ->orderByRaw('handled_by_admin_id IS NULL')->orderBy('id'),
            ]),
            'account' => User::query(),
            default => abort(404),
        };
    }
}
