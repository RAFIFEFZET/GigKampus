<?php

namespace App\Services;

use App\Enums\GigStatus;
use App\Models\Category;
use App\Models\Gig;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class GigStudio
{
    public function save(User $seller, array $terms, ?UploadedFile $portfolio, ?Gig $existing = null): Gig
    {
        $writtenPath = null;

        try {
            return DB::transaction(function () use ($seller, $terms, $portfolio, $existing, &$writtenPath): Gig {
                // Serialize publication with the seller removing their last payment method.
                $seller = User::query()->lock('for no key update')->findOrFail($seller->id);
                $gig = $existing === null ? new Gig : Gig::query()->lockForUpdate()->findOrFail($existing->id);
                Gate::forUser($seller)->authorize($gig->exists ? 'update' : 'create', $gig->exists ? $gig : Gig::class);

                if (! Category::active()->sharedLock()->find($terms['category_id'])) {
                    throw ValidationException::withMessages(['category_id' => 'Pilih kategori yang masih aktif.']);
                }

                $before = $gig->exists ? $gig->getAttributes() : [];
                $oldPortfolio = $gig->portfolio_path;
                $gig->fill($terms)->forceFill(['category_id' => (int) $terms['category_id'], 'seller_id' => $seller->id]);

                if ($portfolio !== null) {
                    $writtenPath = $portfolio->store('portofolio', 'public');
                    if ($writtenPath === false) {
                        throw ValidationException::withMessages(['portfolio' => 'Portofolio gagal disimpan. Silakan coba lagi.']);
                    }
                    $gig->portfolio_path = $writtenPath;
                } elseif ($terms['remove_portfolio'] ?? false) {
                    $gig->portfolio_path = null;
                }

                $gig->save();
                if ($gig->wasRecentlyCreated || $gig->wasChanged()) {
                    Audit::record($seller, $gig->wasRecentlyCreated ? 'gig.created' : 'gig.updated', $gig, $before, $gig->getAttributes());
                }

                if ($oldPortfolio !== $gig->portfolio_path && str_starts_with($oldPortfolio ?? '', 'portofolio/')) {
                    DB::afterCommit(fn () => Storage::disk('public')->delete($oldPortfolio));
                }

                return $gig;
            });
        } catch (Throwable $exception) {
            if (is_string($writtenPath)) {
                Storage::disk('public')->delete($writtenPath);
            }

            throw $exception;
        }
    }

    public function changeStatus(User $seller, Gig $existing, GigStatus $status): Gig
    {
        return DB::transaction(function () use ($seller, $existing, $status): Gig {
            $seller = User::query()->lock('for no key update')->findOrFail($seller->id);
            $gig = Gig::query()->lockForUpdate()->findOrFail($existing->id);
            $gig->setRelation('category', Category::query()->sharedLock()->findOrFail($gig->category_id));
            Gate::forUser($seller)->authorize('changeStatus', [$gig, $status]);

            if ($gig->status === $status) {
                return $gig;
            }

            $before = $gig->only(['status', 'version']);

            if ($status === GigStatus::Inactive) {
                $gig->deactivate($seller);
            } else {
                $gig->activate($seller);
            }
            $gig->save();

            Audit::record($seller, 'gig.'.$gig->status->value, $gig, $before, $gig->only(['status', 'version']));

            return $gig;
        });
    }
}
