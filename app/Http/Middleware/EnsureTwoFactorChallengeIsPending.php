<?php

namespace App\Http\Middleware;

use App\Support\PendingTwoFactorLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorChallengeIsPending
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! PendingTwoFactorLogin::user()) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Sua sessão de verificação expirou. Faça login novamente.']);
        }

        return $next($request);
    }
}
