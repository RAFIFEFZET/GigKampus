<?php

namespace App\Http\Controllers;

use App\Enums\KtmVerificationStatus;
use App\Enums\ReportStatus;
use App\Models\Category;
use App\Models\KtmVerificationRequest;
use App\Models\Report;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(): View
    {
        $ktmQueue = KtmVerificationRequest::query()->where('status', KtmVerificationStatus::PendingReview);

        return view('pages.admin.dashboard', [
            'pendingKtmCount' => (clone $ktmQueue)->count(),
            'pendingReportCount' => Report::query()->where('status', ReportStatus::Pending)->count(),
            'activeCategoryCount' => Category::query()->where('is_active', true)->count(),
            'recentSubmissions' => $ktmQueue->latest('submitted_at')->latest('id')->limit(5)->get(),
        ]);
    }
}
