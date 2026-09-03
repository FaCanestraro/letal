<?php

namespace App\Support;

use App\Models\TwoFactorCode;

/**
 * O código recém-emitido, com o valor em texto puro — que existe apenas
 * durante a requisição, já que no banco guardamos somente o hash.
 */
final readonly class IssuedTwoFactorCode
{
    public function __construct(
        public TwoFactorCode $record,
        public string $code,
    ) {}
}
