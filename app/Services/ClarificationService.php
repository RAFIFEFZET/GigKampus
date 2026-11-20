<?php

namespace App\Services;

use App\Enums\ReportStatus;
use App\Models\Admin;
use App\Models\Report;
use App\Models\ReportClarification;
use App\Models\ReportClarificationResponse;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class ClarificationService
{
    public function request(Admin $admin, Report $report, int $recipientId, string $question): ReportClarification
    {
        $question = Validator::make(['question' => Str::trim($question)], [
            'question' => ['required', 'string', 'min:5', 'max:2000', 'not_regex:/\x00/'],
        ])->validate()['question'];

        return DB::transaction(function () use ($admin, $report, $recipientId, $question): ReportClarification {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);
            abort_unless($report->isHandledBy($admin), 403);
            $recipient = ReportService::parties($report)->find($recipientId);
            abort_if($recipient === null, 403);
            $at = now()->toImmutable();
            $clarification = $report->clarifications()->forceCreate([
                'requested_by_admin_id' => $admin->id, 'recipient_user_id' => $recipient->id,
                'question' => $question, 'requested_at' => $at, 'due_at' => $at->addHours(24),
            ]);
            Audit::record($admin, 'clarification.requested', $clarification, [], [
                'recipient_user_id' => $recipient->id, 'due_at' => $clarification->due_at->toIso8601String(),
            ], report: $report);
            ReportService::notify($report, $recipient, 'clarification.requested', 'Permintaan klarifikasi',
                'Admin meminta klarifikasi dalam 24 jam.', route('reports.clarifications.show', $clarification, false));

            return $clarification;
        });
    }

    public function respond(User $user, ReportClarification $clarification, array $input): ReportClarificationResponse
    {
        abort_unless($user->hasId($clarification->recipient_user_id), 403);
        if (is_string($input['response_text'] ?? null)) {
            $text = Str::trim($input['response_text']);
            $input['response_text'] = $text === '' ? null : $text;
        }
        $input = Validator::make($input, [
            'response_text' => ['nullable', 'required_without_all:attachment,evidence_url', 'string', 'max:5000', 'not_regex:/\x00/'],
            ...ReportEvidence::rules(),
        ], [
            'response_text.required_without_all' => 'Isi jawaban atau sertakan bukti.',
            'response_text.max' => 'Jawaban maksimal 5.000 karakter.',
            'attachment.max' => 'Berkas maksimal 20 MB.',
            'evidence_url.url' => 'Gunakan tautan HTTPS yang valid.',
            'evidence_url.prohibits' => 'Pilih satu berkas atau satu tautan HTTPS.',
        ])->validate();

        return ReportEvidence::transaction("reports/clarifications/{$clarification->id}", $input,
            function (Closure $evidence, ?ReportClarificationResponse &$record) use ($user, $clarification, $input): ReportClarificationResponse {
                // The same report lock serializes replies and the final decision.
                $report = Report::query()->lockForUpdate()->findOrFail($clarification->report_id);
                abort_if($report->status !== ReportStatus::Pending, 409, 'Kasus sudah diputus.');
                $record = $clarification->responses()->forceCreate([
                    'respondent_id' => $user->id, 'response_text' => $input['response_text'] ?? null,
                    'submitted_at' => now(), ...$evidence(),
                ]);
                Audit::record($user, 'clarification.responded', $record, [], ['late' => $record->isLate()], report: $report);
                ReportService::notify($report, $report->handledByAdmin, 'clarification.responded', 'Jawaban klarifikasi diterima',
                    'Penerima telah mengirim jawaban atau bukti susulan.', route('admin.reports.show', $report, false));

                return $record;
            });
    }
}
