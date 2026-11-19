<?php

namespace App\Http\Controllers;

use App\Models\KtmVerificationRequest;
use App\Services\KtmVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class KtmVerificationController extends Controller
{
    public function store(Request $request, KtmVerification $ktm): RedirectResponse
    {
        Gate::authorize('create', KtmVerificationRequest::class);

        $request->validate([
            'photo' => ['required', 'image', 'mimetypes:image/jpeg,image/png', 'extensions:jpg,jpeg,png', 'max:5120'],
        ], [
            'photo.required' => 'Pilih foto KTM terlebih dahulu.',
            'photo.image' => 'Unggah foto KTM dalam format JPG, JPEG, atau PNG.',
            'photo.mimetypes' => 'Unggah foto KTM dalam format JPG, JPEG, atau PNG.',
            'photo.extensions' => 'Gunakan ekstensi JPG, JPEG, atau PNG.',
            'photo.max' => 'Ukuran foto KTM maksimal 5 MB.',
            'photo.uploaded' => 'Foto KTM gagal diunggah. Ukuran maksimum 5 MB.',
        ]);

        $ktm->submit($request->user(), $request->file('photo'));

        return to_route('ktm.index')->with('status', 'Foto KTM berhasil dikirim.');
    }
}
