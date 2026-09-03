<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class SecurityController extends Controller
{
    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()->mixedCase()],
        ], [
            'current_password.current_password' => 'A senha atual informada está incorreta.',
        ]);

        $request->user()->update(['password' => $validated['password']]);

        return back()->with('success', 'Senha alterada com sucesso.');
    }

    public function updateTwoFactor(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'two_factor_enabled' => ['required', 'boolean'],
        ]);

        $request->user()->update($validated);

        return back()->with(
            'success',
            $validated['two_factor_enabled']
                ? 'Verificação em duas etapas ativada.'
                : 'Verificação em duas etapas desativada.'
        );
    }
}
