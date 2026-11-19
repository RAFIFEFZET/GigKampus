<?php

namespace App\Http\Controllers\Identity;

use App\Models\Admin;
use App\Rules\PasswordText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\LoginRateLimiter;

final class AuthenticationController
{
    public function login(Request $request, LoginRateLimiter $limiter): RedirectResponse|Response
    {
        $message = 'Email atau password tidak sesuai.';

        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:254', 'email:rfc'],
            'password' => [new PasswordText(min: 1, message: $message)],
        ], ['email' => $message]);

        $credentials['email'] = Str::lower($credentials['email']);
        $request->merge(['email' => $credentials['email']]);

        if ($limiter->tooManyAttempts($request)) {
            $seconds = max(1, $limiter->availableIn($request));
            $message = "Terlalu banyak percobaan masuk. Coba lagi dalam {$seconds} detik.";
            $headers = ['Retry-After' => (string) $seconds];

            if ($request->expectsJson()) {
                abort(429, $message, $headers);
            }

            return response()->view('pages.auth.login', ['throttleMessage' => $message, 'email' => $credentials['email']], 429, $headers);
        }

        $admin = Admin::query()->where('email', $credentials['email'])->exists();
        $guard = Auth::guard($admin ? 'admin' : 'web');

        if (! $guard->attempt($credentials)) {
            $limiter->increment($request);

            throw ValidationException::withMessages(['email' => $message]);
        }

        Auth::guard($admin ? 'web' : 'admin')->logout();
        $limiter->clear($request);
        $request->session()->regenerate();

        return redirect()->route($admin ? 'admin.index' : ($guard->user()->is_verified ? 'home' : 'ktm.index'));
    }

    public function register(Request $request, CreatesNewUsers $creator): RedirectResponse
    {
        $user = $creator->create($request->all());

        Auth::guard('admin')->logout();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('ktm.index');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
