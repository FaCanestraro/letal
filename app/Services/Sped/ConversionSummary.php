<?php

namespace App\Services\Sped;

use App\Enums\SpedModel;

final readonly class ConversionSummary
{
    /**
     * @param  array<string, int>  $rowsByRegister
     * @param  list<string>  $outputFiles
     */
    public function __construct(
        public SpedModel $model,
        public int $inputFiles,
        public array $rowsByRegister,
        public array $outputFiles,
    ) {}

    public function totalRows(): int
    {
        return array_sum($this->rowsByRegister);
    }

    public function sheetCount(): int
    {
        return count($this->rowsByRegister);
    }
}
