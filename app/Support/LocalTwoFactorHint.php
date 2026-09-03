<?php

namespace App\Support;

use Illuminate\Support\Facades\Session;

/**
 * Conveniência de desenvolvimento: enquanto não houver um provedor de e-mail
 * configurado, exibe o código do segundo fator na própria tela de verificação.
 *
 * Controlado por config('two_factor.expose_code_on_screen'), que liga sozinho
 * apenas em APP_ENV=local com MAIL_MAILER=log. Fora disso os métodos são
 * inertes e nada é guardado na sessão.
 */
final class LocalTwoFactorHint
{
    public const KEY = 'auth.two_factor_hint';

    public static function isAvailable(): bool
    {
        return (bool) config('two_factor.expose_code_on_screen');
    }

    public static function remember(string $code): void
    {
        if (self::isAvailable()) {
            Session::put(self::KEY, $code);
        }
    }

    public static function current(): ?string
    {
        if (! self::isAvailable()) {
            return null;
        }

        $code = Session::get(self::KEY);

        return is_string($code) ? $code : null;
    }

    public static function forget(): void
    {
        Session::forget(self::KEY);
    }
}
