<?php

namespace App\Services\Sped;

use DateTimeImmutable;

/**
 * Conversão de valores entre o texto do SPED e o tipo nativo da planilha.
 */
final class SpedValue
{
    /**
     * Texto do .txt para o valor que vai na célula.
     */
    public static function toCell(string $raw, string $type): string|float|DateTimeImmutable|null
    {
        if ($raw === '') {
            return null;
        }

        return match ($type) {
            'data' => self::parseDate($raw) ?? $raw,
            'numero' => is_numeric($number = str_replace(',', '.', $raw)) ? (float) $number : $raw,
            default => $raw,
        };
    }

    /**
     * Valor da célula de volta para o texto do .txt.
     *
     * Números voltam na forma mínima (sem zeros à direita), que é como a
     * maioria esmagadora do acervo já vem e o que o PVA aceita. O estilo
     * "1001,00" não sobrevive à ida e volta: ele se perde no instante em que
     * o valor vira número na planilha.
     */
    public static function toText(string|float|int|DateTimeImmutable|null $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($type === 'data') {
            if ($value instanceof DateTimeImmutable) {
                return $value->format('dmY');
            }

            return self::parseDate((string) $value)?->format('dmY') ?? (string) $value;
        }

        if ($type === 'numero') {
            if (is_string($value)) {
                return $value;
            }

            $text = rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.');

            return str_replace('.', ',', $text === '' || $text === '-' ? '0' : $text);
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->format('dmY');
        }

        // Planilhas devolvem inteiros como float; evita "3106200.0" em campo texto.
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return (string) $value;
    }

    /**
     * Datas do SPED chegam como ddmmaaaa.
     */
    public static function parseDate(string $raw): ?DateTimeImmutable
    {
        if (preg_match('/^\d{8}$/', $raw) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!dmY', $raw);

        return ($date !== false && $date->format('dmY') === $raw) ? $date : null;
    }
}
