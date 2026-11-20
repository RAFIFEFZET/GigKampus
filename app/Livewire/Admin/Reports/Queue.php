<?php

namespace App\Livewire\Admin\Reports;

use App\Enums\ReportStatus;
use App\Models\Admin;
use App\Models\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class Queue extends Component
{
    use WithPagination;

    public string $filter = 'active';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin, 403);
        $reports = Report::query()
            ->when($this->filter === 'history',
                fn (Builder $query) => $query->where('status', '!=', ReportStatus::Pending)->latest('decided_at'),
                fn (Builder $query) => $query->where('status', ReportStatus::Pending)->oldest('created_at'))
            ->when($this->filter === 'mine', fn (Builder $query) => $query->where('handled_by_admin_id', $admin->id))
            ->orderBy('id')->paginate(12);

        return view('livewire.admin.reports.queue', compact('reports', 'admin'));
    }
}
