<?php

namespace App\Enums;

enum ConversionDirection: string
{
    case ToSpreadsheet = 'to_spreadsheet';
    case ToText = 'to_text';

    public function label(): string
    {
        return match ($this) {
            self::ToSpreadsheet => 'SPED (.txt) → Excel',
            self::ToText => 'Excel → SPED (.txt)',
        };
    }
}
