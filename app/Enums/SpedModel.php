<?php

namespace App\Enums;

enum SpedModel: string
{
    case Ecd = 'ecd';
    case Ecf = 'ecf';
    case EfdContribuicoes = 'efd-contribuicoes';
    case EfdIcms = 'efd-icms';

    public function label(): string
    {
        return match ($this) {
            self::Ecd => 'ECD — Escrituração Contábil Digital',
            self::Ecf => 'ECF — Escrituração Contábil Fiscal',
            self::EfdContribuicoes => 'EFD Contribuições (PIS/COFINS)',
            self::EfdIcms => 'EFD ICMS/IPI (Sped Fiscal)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Ecd => 'ECD',
            self::Ecf => 'ECF',
            self::EfdContribuicoes => 'EFD Contribuições',
            self::EfdIcms => 'Sped Fiscal',
        };
    }

    public function layoutPath(): string
    {
        return resource_path("sped-layouts/{$this->value}.json");
    }

    /**
     * Identifica o modelo pela linha de abertura (registro 0000).
     *
     * ECD e ECF se declaram por um literal no primeiro campo. As duas EFD
     * não têm literal, e o que as separa é a posição da data de início:
     * na EFD ICMS/IPI ela é o 3º campo; na EFD Contribuições, o 5º, porque
     * TIPO_ESCRIT, IND_SIT_ESP e NUM_REC_ANTERIOR vêm antes.
     */
    public static function detectFromOpeningLine(string $line): ?self
    {
        $fields = explode('|', trim($line));

        if (count($fields) < 6 || $fields[1] !== '0000') {
            return null;
        }

        $isDate = static fn (int $index): bool => isset($fields[$index])
            && preg_match('/^\d{8}$/', $fields[$index]) === 1;

        return match (true) {
            $fields[2] === 'LECD' => self::Ecd,
            $fields[2] === 'LECF' => self::Ecf,
            $isDate(4) => self::EfdIcms,
            $isDate(6) => self::EfdContribuicoes,
            default => null,
        };
    }
}
