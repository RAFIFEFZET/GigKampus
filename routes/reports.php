<?php

use App\Enums\ReportType;
use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->prefix('laporan')->name('reports.')->group(function (): void {
    Route::view('/', 'pages.reports.index')->name('index');
    Route::get('/klarifikasi/{clarification}', [ReportController::class, 'clarification'])
        ->whereNumber('clarification')->name('clarifications.show');
    Route::post('/klarifikasi/{clarification}', [ReportController::class, 'respond'])
        ->whereNumber('clarification')->name('clarifications.respond');
    Route::get('/buat/{type}/{target}', [ReportController::class, 'create'])
        ->whereIn('type', array_column(ReportType::cases(), 'value'))->whereNumber('target')->name('create');
    Route::post('/buat/{type}/{target}', [ReportController::class, 'store'])
        ->whereIn('type', array_column(ReportType::cases(), 'value'))->whereNumber('target')->name('store');
    Route::get('/{report}', [ReportController::class, 'show'])->whereNumber('report')->name('show');
});

Route::middleware('auth:admin')->prefix('admin')->name('admin.')->group(function (): void {
    Route::view('/laporan', 'pages.admin.reports.index')->name('reports.index');
    Route::get('/laporan/{report}', [AdminReportController::class, 'show'])->whereNumber('report')->name('reports.show');
    Route::post('/laporan/{report}/ambil', [AdminReportController::class, 'claim'])->whereNumber('report')->name('reports.claim');
    Route::post('/laporan/{report}/klarifikasi', [AdminReportController::class, 'clarify'])->whereNumber('report')->name('reports.clarify');
    Route::post('/laporan/{report}/putusan', [AdminReportController::class, 'decide'])->whereNumber('report')->name('reports.decide');
    Route::view('/moderasi', 'pages.admin.moderation.index')->name('moderation.index');
});
