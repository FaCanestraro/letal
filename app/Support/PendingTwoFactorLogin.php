<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;

/**
 * Guarda o estado intermediário do login: a senha já foi validada,
 * mas o usuário ainda não confirmou o código do segundo fator.
 */
class PendingTwoFactorLogin
{
    public const KEY = 'auth.two_factor';

    public static function put(User $user, bool $remember): void
    {
        Session::put(self::KEY, [
            'user_id' => $user->getKey(),
            'remember' => $remember,
            'expires_at' => Carbon::now()
                ->addMinutes((int) config('two_factor.challenge_ttl_minutes'))
                ->toIso8601String(),
        ]);
    }

    public static function user(): ?User
    {
        $data = Session::get(self::KEY);

        if (! is_array($data) || Carbon::parse($data['expires_at'])->isPast()) {
            self::forget();

            return null;
        }

        return User::find($data['user_id']);
    }

    public static function shouldRemember(): bool
    {
        return (bool) (Session::get(self::KEY.'.remember') ?? false);
    }

    public static function forget(): void
    {
        Session::forget(self::KEY);
    }
}
