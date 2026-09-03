<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\TwoFactorService;
use App\Support\LocalTwoFactorHint;
use App\Support\PendingTwoFactorLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => true,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->findUserForLogin();

        if (! config('two_factor.enabled') || ! $user->two_factor_enabled) {
            auth()->login($user, $request->boolean('remember'));
            $request->session()->regenerate();

            $user->forceFill(['last_login_at' => now()])->save();

            return redirect()->intended(route('dashboard'));
        }

        PendingTwoFactorLogin::put($user, $request->boolean('remember'));

        LocalTwoFactorHint::remember($this->twoFactor->issue($user, $request)->code);

        return redirect()->route('two-factor.create');
    }

    public function destroy(Request $request): RedirectResponse
    {
        auth()->logout();

        LocalTwoFactorHint::forget();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Sessão encerrada.');
    }
}
