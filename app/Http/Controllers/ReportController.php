<?php

namespace App\Http\Controllers;

use App\Enums\ReportType;
use App\Models\Order;
use App\Models\Report;
use App\Models\ReportClarification;
use App\Policies\ReportPolicy;
use App\Services\ClarificationService;
use App\Services\ReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ReportController extends Controller
{
    public function create(Request $request, ReportType $type, int $target, ReportPolicy $policy): View
    {
        $subject = ReportService::target($type, $target);
        $student = $request->user('web');
        abort_unless($policy->submit($student, $subject), 403);

        $reason = $subject instanceof Order ? $policy->complaintUnavailable($student, $subject) : null;
        abort_if($reason !== null, 409, $reason ?? '');

        return view('pages.reports.create', compact('type', 'target'));
    }

    public function store(Request $request, ReportType $type, int $target, ReportService $reports): RedirectResponse
    {
        $report = $reports->submit($request->user('web'), $type, $target, $request->all());

        return to_route('reports.show', $report)->with('status', 'Laporan telah dikirim untuk peninjauan.');
    }

    public function show(Request $request, Report $report, ReportPolicy $policy): View
    {
        $student = $request->user('web');
        $isReporter = $student->hasId($report->reporter_id);
        abort_unless($isReporter || $report->order?->hasParticipant($student), 403);
        $clarifications = $report->clarifications()->where('recipient_user_id', $student->id)->latest('requested_at')->get();
        $canSeeEvidence = $policy->evidence($student, $report);

        return view('pages.reports.show', compact('report', 'clarifications', 'isReporter', 'canSeeEvidence'));
    }

    public function clarification(Request $request, ReportClarification $clarification, ReportPolicy $policy): View
    {
        abort_unless($policy->clarification($request->user('web'), $clarification), 403);
        $clarification->load(['report', 'responses' => fn ($query) => $query->orderBy('submitted_at')->orderBy('id')]);

        return view('pages.reports.clarification', compact('clarification'));
    }

    public function respond(Request $request, ReportClarification $clarification, ClarificationService $service): RedirectResponse
    {
        $service->respond($request->user('web'), $clarification, $request->all());

        return to_route('reports.clarifications.show', $clarification)->with('status', 'Jawaban klarifikasi tersimpan.');
    }
}
