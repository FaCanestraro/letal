<?php

namespace App\Http\Controllers\Auth;

use App\Enums\TwoFactorResult;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\LocalTwoFactorHint;
use App\Support\PendingTwoFactorLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function create(): Response
    {
        /** @var User $user */
        $user = PendingTwoFactorLogin::user();

        return Inertia::render('Auth/TwoFactorChallenge', [
            'maskedEmail' => $user->maskedEmail(),
            'codeLength' => (int) config('two_factor.code_length'),
            'expiresInMinutes' => (int) config('two_factor.expires_in_minutes'),
            'resendAvailableIn' => $this->twoFactor->secondsUntilResend($user),
            'localHintCode' => LocalTwoFactorHint::current(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:'.config('two_factor.code_length')],
        ], [
            'code.required' => 'Informe o código enviado para o seu e-mail.',
            'code.digits' => 'O código deve ter :digits dígitos.',
        ]);

        /** @var User $user */
        $user = PendingTwoFactorLogin::user();

        $result = $this->twoFactor->verify($user, $validated['code']);

        if ($result !== TwoFactorResult::Success) {
            throw ValidationException::withMessages(['code' => $result->message()]);
        }

        $remember = PendingTwoFactorLogin::shouldRemember();
        PendingTwoFactorLogin::forget();
        LocalTwoFactorHint::forget();

        auth()->login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    public function resend(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = PendingTwoFactorLogin::user();

        if (($wait = $this->twoFactor->secondsUntilResend($user)) > 0) {
            throw ValidationException::withMessages([
                'code' => "Aguarde {$wait} segundos para solicitar um novo código.",
            ]);
        }

        LocalTwoFactorHint::remember($this->twoFactor->issue($user, $request)->code);

        return back()->with('success', 'Enviamos um novo código para o seu e-mail.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        PendingTwoFactorLogin::forget();
        LocalTwoFactorHint::forget();

        return redirect()->route('login');
    }
}
