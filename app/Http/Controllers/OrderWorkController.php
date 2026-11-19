<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Revision;
use App\Services\Orders\OrderDeliveryService;
use App\Services\Orders\ReviewService;
use App\Services\Orders\RevisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OrderWorkController extends Controller
{
    public function deliver(Request $request, Order $order, OrderDeliveryService $deliveries): RedirectResponse
    {
        $result = $request->validate([
            'delivery_file' => ['bail', 'required_without:result_url', 'nullable', 'file', 'max:20480', 'prohibits:result_url'],
            'result_url' => ['bail', 'required_without:delivery_file', 'nullable', 'string', 'max:2048', 'url:https'],
            'delivery_note' => ['nullable', 'string', 'max:2000', 'not_regex:/\x00/'],
            'revision_id' => ['nullable', 'integer', 'min:1'],
            ...$this->stageRules(),
        ], [
            'delivery_file.required_without' => 'Unggah satu berkas atau masukkan tautan HTTPS hasil.',
            'delivery_file.prohibits' => 'Pilih satu berkas atau satu tautan hasil.',
            'delivery_file.max' => 'Ukuran berkas hasil maksimal 20 MB.',
            'result_url.required_without' => 'Masukkan tautan HTTPS atau unggah berkas hasil.',
            'result_url.url' => 'Gunakan tautan HTTPS yang valid.',
        ]);

        $deliveries->submit(
            $request->user(),
            $order,
            $result['delivery_file'] ?? null,
            $result['result_url'] ?? null,
            $result['delivery_note'] ?? null,
            isset($result['revision_id']) ? (int) $result['revision_id'] : null,
            $this->stageVersion($result),
        );

        return to_route('orders.show', $order)->with('status', 'Hasil pesanan berhasil dikirim.');
    }

    public function requestRevision(Request $request, Order $order, RevisionService $revisions): RedirectResponse
    {
        $terms = $request->validate([
            'revision_note' => ['required', 'string', 'min:20', 'max:5000', 'not_regex:/\x00/'],
            ...$this->stageRules(),
        ]);
        $revisions->request($request->user(), $order, $terms['revision_note'], $this->stageVersion($terms));

        return to_route('orders.show', $order)->with('status', 'Revisi berhasil diajukan.');
    }

    public function startRevision(Request $request, Order $order, Revision $revision, RevisionService $revisions): RedirectResponse
    {
        $revisions->start($request->user(), $order, $revision);

        return to_route('orders.show', $order)->with('status', 'Revisi mulai dikerjakan.');
    }

    public function complete(Request $request, Order $order, OrderDeliveryService $deliveries): RedirectResponse
    {
        $deliveries->complete($request->user(), $order, $this->stageVersion($request->validate($this->stageRules())));

        return to_route('orders.show', $order)->with('status', 'Pesanan sudah selesai.');
    }

    public function review(Request $request, Order $order, ReviewService $reviews): RedirectResponse
    {
        $terms = $request->validate([
            'rating' => ['required', 'regex:/\A[1-5]\z/'],
            'comment' => ['nullable', 'string', 'max:2000', 'not_regex:/\x00/'],
        ], [
            'rating.regex' => 'Pilih penilaian dari 1 sampai 5.',
        ]);
        $reviews->submit($request->user(), $order, (int) $terms['rating'], $terms['comment'] ?? null);

        return to_route('orders.show', $order)->with('status', 'Ulasan berhasil dikirim.');
    }

    private function stageRules(): array
    {
        return ['stage_version' => ['nullable', 'integer', 'between:1,2147483647']];
    }

    private function stageVersion(array $validated): ?int
    {
        return isset($validated['stage_version']) ? (int) $validated['stage_version'] : null;
    }
}
