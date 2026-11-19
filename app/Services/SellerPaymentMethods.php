<?php

namespace App\Services;

use App\Enums\GigStatus;
use App\Enums\PaymentMethod;
use App\Enums\PriceOfferStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class SellerPaymentMethods
{
    private const BANK_FIELDS = ['bank_id', 'bank_account_number', 'bank_account_holder_name'];

    private const QRIS_FIELDS = ['qris_image_path', 'qris_holder_name'];

    public function update(User $user, array $input): void
    {
        $newPath = null;

        try {
            DB::transaction(function () use ($user, $input, &$newPath): void {
                $seller = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $before = $this->availability($seller);
                $data = $this->validate($seller, $input);

                if (isset($data['bank_id'])) {
                    $seller->forceFill([
                        'bank_id' => $data['bank_id'],
                        'bank_account_number' => $data['bank_account_number'],
                        'bank_account_holder_name' => $data['bank_account_holder_name'],
                    ]);
                }

                if (isset($data['qris_image'])) {
                    $newPath = $data['qris_image']->store("qris/{$seller->id}", 'private');
                    abort_unless(is_string($newPath), 500, 'Gambar QRIS gagal disimpan.');
                    $seller->qris_image_path = $newPath;
                }

                if (isset($data['qris_holder_name'])) {
                    $seller->qris_holder_name = $data['qris_holder_name'];
                }

                $this->save($seller, $before);
            });
        } catch (Throwable $exception) {
            if (is_string($newPath)) {
                Storage::disk('private')->delete($newPath);
            }

            throw $exception;
        }
    }

    public function remove(User $user, PaymentMethod $method): void
    {
        DB::transaction(function () use ($user, $method): void {
            $seller = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $before = $this->availability($seller);
            $fields = $method === PaymentMethod::BankTransfer ? self::BANK_FIELDS : self::QRIS_FIELDS;
            $seller->forceFill(array_fill_keys($fields, null));

            if ($seller->isDirty() && ! $seller->hasCompletePaymentMethod() && (
                $seller->gigs()->where('status', GigStatus::Active->value)->exists()
                || $seller->priceOffers()->where('status', PriceOfferStatus::Pending->value)->exists()
            )) {
                throw new ConflictHttpException('Metode pembayaran terakhir diperlukan selama ada jasa aktif atau penawaran yang menunggu.');
            }

            $this->save($seller, $before);
        });
    }

    private function validate(User $seller, array $input): array
    {
        return Validator::make($input, [
            'bank_id' => ['bail', 'nullable', 'required_with:bank_account_number,bank_account_holder_name', 'integer', 'min:1', 'exists:banks,id'],
            'bank_account_number' => ['nullable', 'required_with:bank_id,bank_account_holder_name', 'string', 'regex:/\A[0-9]{1,50}\z/'],
            'bank_account_holder_name' => ['nullable', 'required_with:bank_id,bank_account_number', 'string', 'max:100'],
            'qris_image' => [
                'nullable',
                ...($seller->hasQrisPaymentMethod() ? [] : ['required_with:qris_holder_name']),
                'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120',
            ],
            'qris_holder_name' => ['nullable', 'required_with:qris_image', 'string', 'max:100'],
        ], [
            'bank_id.required_with' => 'Pilih bank untuk melengkapi rekening.',
            'bank_id.exists' => 'Bank yang dipilih tidak tersedia.',
            'bank_account_number.required_with' => 'Nomor rekening wajib diisi.',
            'bank_account_number.regex' => 'Nomor rekening harus terdiri dari 1–50 angka.',
            'bank_account_holder_name.required_with' => 'Nama pemilik rekening wajib diisi.',
            'qris_image.required_with' => 'Unggah gambar QRIS.',
            'qris_image.image' => 'Unggah gambar QRIS berformat JPG, JPEG, atau PNG.',
            'qris_image.mimes' => 'Unggah gambar QRIS berformat JPG, JPEG, atau PNG.',
            'qris_image.max' => 'Ukuran gambar QRIS maksimal 5 MB.',
            'qris_holder_name.required_with' => 'Nama merchant QRIS wajib diisi.',
        ])->validate();
    }

    private function availability(User $seller): array
    {
        return ['bank_transfer' => $seller->hasBankPaymentMethod(), 'qris' => $seller->hasQrisPaymentMethod()];
    }

    private function save(User $seller, array $before): void
    {
        if ($seller->isClean()) {
            return;
        }

        // Previous QRIS files may still be referenced by immutable order snapshots.
        $seller->save();
        Audit::record($seller, 'account.payment_methods_updated', $seller, $before, $this->availability($seller));
    }
}
