<?php

namespace App\Services\Sped;

/**
 * Uma linha pronta da planilha: o registro que a originou e todas as células
 * já na ordem final das colunas, ainda como texto do SPED.
 */
final readonly class LeafRow
{
    /**
     * @param  list<string>  $cells
     */
    public function __construct(
        public string $register,
        public array $cells,
    ) {}
}
