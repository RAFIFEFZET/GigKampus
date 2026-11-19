<?php

namespace App\Http\Controllers;

use App\Models\KtmVerificationRequest;
use App\Models\Order;
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
                $order = Order::query()->findOrFail($id);
                abort_unless($qris->viewOrderSnapshot($request->user(), $order), 403);
                $path = $order->qris_image_path_snapshot;
                break;
            default:
                abort(404);
        }

        abort_unless($path && $disk->exists($path), 404);
        $mime = $disk->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png'], true), 404);
        $name = ($jenis === 'ktm' ? 'foto-ktm' : 'qris').($mime === 'image/png' ? '.png' : '.jpg');

        return $disk->response($path, $name, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
