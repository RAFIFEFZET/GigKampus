<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Report;
use App\Services\ClarificationService;
use App\Services\MediationDecisionService;
use App\Services\ReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AdminReportController extends Controller
{
    public function show(Request $request, Report $report): View
    {
        // Keep access and the read-only chat query on the same side of a concurrent decision.
        return DB::transaction(fn () => $this->detail(
            $request->user('admin'), Report::query()->sharedLock()->findOrFail($report->id), $request->boolean('chat'),
        ));
    }

    private function detail(Admin $admin, Report $report, bool $viewChat): View
    {
        $isHandler = $admin->hasId($report->handled_by_admin_id);
        abort_if($report->handled_by_admin_id !== null && ! $isHandler, 403);
        $canHandle = $report->isHandledBy($admin);
        abort_if($viewChat && ! $canHandle, 403);

        $report->load(['reporter', 'gig', 'serviceRequest', 'review', 'order', 'chatRoom']);
        $room = $canHandle ? ($report->chatRoom ?? $report->order?->chatRoom) : null;
        $messages = $room && $viewChat
            ? $room->messages()->with('sender:id,name')->latest('id')->paginate(20)->withQueryString()
            : null;
        $clarifications = $isHandler ? $report->clarifications()->with(['recipient', 'responses' => fn ($query) => $query
            ->orderBy('submitted_at')->orderBy('id')])->oldest('requested_at')->get() : collect();
        $payment = $canHandle ? $report->order?->payment : null;
        $proofs = $payment?->proofs()->latest('attempt_no')->get() ?? collect();
        $deliveries = $canHandle ? ($report->order?->deliveries()->with('revision')->oldest('id')->get() ?? collect()) : collect();
        $parties = $canHandle ? ReportService::parties($report) : collect();
        $outcomes = $canHandle ? MediationDecisionService::outcomes($report) : [];
        $actions = $canHandle ? MediationDecisionService::moderationOptions($report) : [];
        $awaitingClarification = $canHandle && $clarifications->contains(
            fn ($clarification) => $clarification->due_at->isFuture() && $clarification->responses->isEmpty(),
        );

        return view('pages.admin.reports.show', compact(
            'report', 'canHandle', 'room', 'messages', 'clarifications', 'payment', 'proofs', 'deliveries', 'parties', 'outcomes', 'actions',
            'awaitingClarification',
        ));
    }

    public function claim(Request $request, Report $report, ReportService $reports): RedirectResponse
    {
        $reports->claim($request->user('admin'), $report);

        return to_route('admin.reports.show', $report)->with('status', 'Kasus berhasil diambil.');
    }

    public function clarify(Request $request, Report $report, ClarificationService $service): RedirectResponse
    {
        $input = $request->validate(['recipient_user_id' => ['required', 'integer', 'min:1'], 'question' => ['required', 'string']]);
        $service->request($request->user('admin'), $report, (int) $input['recipient_user_id'], $input['question']);

        return to_route('admin.reports.show', $report)->with('status', 'Permintaan klarifikasi telah dikirim.');
    }

    public function decide(Request $request, Report $report, MediationDecisionService $service): RedirectResponse
    {
        $service->decide($request->user('admin'), $report, $request->all());

        return to_route('admin.reports.show', $report)->with('status', 'Putusan akhir telah disimpan.');
    }
}
