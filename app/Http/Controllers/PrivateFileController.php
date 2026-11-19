<?php

namespace App\Http\Controllers;

use App\Models\KtmVerificationRequest;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\PaymentProof;
use App\Models\User;
use App\Policies\QrisFilePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateFileController extends Controller
{
    public function __invoke(Request $request, string $jenis, string $id, QrisFilePolicy $qris): StreamedResponse
    {
        // PostgreSQL IDs are positive signed 64-bit integers.
        abort_if(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false, 404);

        $disk = Storage::disk('private');
        $headers = [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        switch ($jenis) {
            case 'ktm':
                $verification = KtmVerificationRequest::query()->findOrFail($id);
                Gate::authorize('view', $verification);
                $path = $verification->file_path;
                break;
            case 'qris':
                $owner = User::query()->findOrFail($id);
                abort_unless($qris->viewCurrent($request->user(), $owner), 403);
                $path = $owner->qris_image_path;
                break;
            case 'qris-pesanan':
            case 'order_qris_snapshot':
                $order = Order::query()->findOrFail($id);
                Gate::authorize('view', $order);
                $path = $order->qris_image_path_snapshot;
                break;
            case 'payment_proof':
                $proof = PaymentProof::query()->with('payment.order')->findOrFail($id);
                Gate::authorize('view', $proof->payment->order);
                $path = $proof->file_path;
                break;
            case 'order_delivery':
                $delivery = OrderDelivery::query()->with('order')->findOrFail($id);
                Gate::authorize('view', $delivery->order);
                abort_unless($delivery->file_path && $disk->exists($delivery->file_path), 404);
                $extension = strtolower(pathinfo($delivery->original_filename ?? '', PATHINFO_EXTENSION));
                $name = 'hasil-pesanan-'.$id.(preg_match('/\A[a-z0-9]{1,8}\z/', $extension) ? '.'.$extension : '');

                return $disk->download($delivery->file_path, $name, [...$headers, 'Content-Type' => 'application/octet-stream']);
            default:
                abort(404);
        }

        abort_unless($path && $disk->exists($path), 404);
        $mime = $disk->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png'], true), 404);
        $label = match ($jenis) {
            'ktm' => 'foto-ktm', 'payment_proof' => 'bukti-bayar', default => 'qris',
        };
        $name = $label.($mime === 'image/png' ? '.png' : '.jpg');

        return $disk->response($path, $name, [...$headers, 'Content-Type' => $mime],
            $request->boolean('unduh') ? 'attachment' : 'inline');
    }
}
