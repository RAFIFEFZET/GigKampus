<?php

namespace App\Livewire\Reports;

use App\Models\Order;
use App\Models\Report;
use App\Models\ReportClarification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class History extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $orderId = null;

    public function render(): View
    {
        $student = auth('web')->user();
        abort_unless($student instanceof User, 403);
        $order = $this->orderId === null ? null : Order::findOrFail($this->orderId);
        abort_if($order !== null && ! $order->hasParticipant($student), 403);

        $reports = Report::query()->where(function (Builder $query) use ($student): void {
            $query->where('reporter_id', $student->id)->orWhereHas('order', fn (Builder $order) => $order
                ->where(fn (Builder $parties) => $parties->where('buyer_id', $student->id)->orWhere('seller_id', $student->id)));
        })->when($order, fn (Builder $query) => $query->where('order_id', $order->id))
            ->latest('id')->paginate(10, pageName: 'reports-page');
        $clarifications = ReportClarification::query()->with('report')->where('recipient_user_id', $student->id)
            ->when($order, fn (Builder $query) => $query->whereHas('report', fn (Builder $report) => $report->where('order_id', $order->id)))
            ->latest('requested_at')->paginate(10, pageName: 'clarifications-page');

        return view('livewire.reports.history', compact('reports', 'clarifications', 'order'));
    }
}
