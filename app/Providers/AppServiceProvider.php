<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        View::composer('layouts.partials.header', function ($view): void {
            $student = Auth::guard('web')->user();
            $admin = Auth::guard('admin')->user();
            $unreadChat = $student ? ChatMessage::query()
                ->whereNull('read_at')
                ->where('sender_id', '!=', $student->getKey())
                ->whereHas('chatRoom', fn (Builder $room) => $room->where(
                    fn (Builder $participants) => $participants->where('buyer_id', $student->getKey())
                        ->orWhere('seller_id', $student->getKey()),
                ))
                ->count() : 0;
            $view->with(compact('student', 'admin', 'unreadChat'));
        });
    }
}
