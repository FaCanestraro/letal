<?php

namespace App\Enums;

enum TwoFactorResult: string
{
    case Success = 'success';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case Throttled = 'throttled';
    case Missing = 'missing';

    public function message(): string
    {
        return match ($this) {
            self::Success => 'Código validado com sucesso.',
            self::Invalid => 'Código incorreto. Confira os dígitos e tente novamente.',
            self::Expired => 'Este código expirou. Solicite um novo código.',
            self::Throttled => 'Número de tentativas excedido. Solicite um novo código.',
            self::Missing => 'Nenhum código ativo encontrado. Solicite um novo código.',
        };
    }
}
