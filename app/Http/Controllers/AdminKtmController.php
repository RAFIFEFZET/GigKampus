<?php

namespace App\Http\Controllers;

use App\Enums\KtmVerificationStatus;
use App\Models\KtmVerificationRequest;
use App\Services\KtmVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminKtmController extends Controller
{
    public function index(Request $request): View
    {
        $filter = $request->query('status', KtmVerificationStatus::PendingReview->value);
        $status = is_string($filter) ? KtmVerificationStatus::tryFrom($filter) : null;

        return view('pages.admin.ktm.index', [
            'filter' => $status?->value ?? 'semua',
            'submissions' => KtmVerificationRequest::query()
                ->when($status, fn ($query) => $query->where('status', $status))
                ->latest('submitted_at')->latest('id')->paginate(15)->withQueryString(),
        ]);
    }

    public function show(KtmVerificationRequest $verification): View
    {
        Gate::authorize('view', $verification);

        return view('pages.admin.ktm.show', compact('verification'));
    }

    public function approve(Request $request, KtmVerificationRequest $verification, KtmVerification $ktm): RedirectResponse
    {
        return $this->review($request, $verification, $ktm, true);
    }

    public function reject(Request $request, KtmVerificationRequest $verification, KtmVerification $ktm): RedirectResponse
    {
        return $this->review($request, $verification, $ktm, false);
    }

    private function review(Request $request, KtmVerificationRequest $verification, KtmVerification $ktm, bool $approve): RedirectResponse
    {
        Gate::authorize('review', $verification);
        $validated = $request->validate([
            'reason' => [$approve ? 'nullable' : 'required', 'string', 'min:5', 'max:1000'],
        ], [
            'reason.required' => 'Tuliskan alasan penolakan.',
            'reason.min' => 'Alasan harus berisi minimal 5 karakter.',
            'reason.max' => 'Alasan harus berisi maksimal 1.000 karakter.',
        ]);

        $ktm->review($request->user(), $verification, $approve, $validated['reason'] ?? null);

        return to_route('admin.ktm.show', $verification)->with(
            'status', $approve ? 'Pengajuan KTM disetujui.' : 'Pengajuan KTM ditolak.'
        );
    }
}
