<?php

namespace App\Services\Sped;

use DateTimeImmutable;
use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Lê a planilha de volta, devolvendo para cada linha a cadeia de registros que
 * a originou, já convertida ao texto do SPED.
 */
final class SpedWorkbookReader
{
    public function __construct(private readonly SpedLayout $layout) {}

    /**
     * @return Generator<int, array{key: array{0: string, 1: string, 2: string}, chain: list<array{reg: string, values: list<string>}>}>
     */
    public function read(string $path): Generator
    {
        $reader = new Reader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $register = $sheet->getName();

                if (! $this->layout->hasRegister($register)) {
                    continue; // aba desconhecida: ignorada em vez de derrubar a conversão
                }

                $ancestry = $this->layout->ancestry($register);
                $types = $this->columnTypes($ancestry);

                foreach ($sheet->getRowIterator() as $number => $row) {
                    if ($number === 1) {
                        continue; // cabeçalho
                    }

                    $cells = $row->cells;

                    if ($this->isBlank($cells)) {
                        continue;
                    }

                    yield $this->toChain($ancestry, $types, $cells);
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  list<string>  $ancestry
     * @return list<string>
     */
    private function columnTypes(array $ancestry): array
    {
        $types = ['data', 'data', 'texto'];

        foreach ($ancestry as $register) {
            $types[] = 'texto'; // coluna REG

            foreach ($this->layout->fields($register) as $field) {
                $types[] = $field['tipo'];
            }
        }

        return $types;
    }

    /**
     * @param  list<string>  $ancestry
     * @param  list<string>  $types
     * @param  list<Cell>  $cells
     * @return array{key: array{0: string, 1: string, 2: string}, chain: list<array{reg: string, values: list<string>}>}
     */
    private function toChain(array $ancestry, array $types, array $cells): array
    {
        $text = [];

        foreach ($types as $index => $type) {
            // O .xlsx omite as células vazias à direita: a linha pode ser mais
            // curta que o cabeçalho.
            $value = ($cells[$index] ?? null)?->getValue();

            if ($value instanceof \DateTimeInterface) {
                $value = DateTimeImmutable::createFromInterface($value);
            }

            $text[$index] = SpedValue::toText(
                is_bool($value) ? ($value ? '1' : '0') : $value,
                $type,
            );
        }

        $key = [$text[0] ?? '', $text[1] ?? '', $text[2] ?? ''];

        $chain = [];
        $cursor = 3;

        foreach ($ancestry as $register) {
            $width = $this->layout->fieldCount($register);
            $chain[] = [
                'reg' => $register,
                'values' => array_values(array_slice($text, $cursor + 1, $width) + array_fill(0, $width, '')),
            ];
            $cursor += 1 + $width;
        }

        if ($key[0] === '' || $key[2] === '') {
            throw new RuntimeException(
                'Há linha sem ID_DT_INI ou ID_CNPJ na planilha. Essas colunas identificam o arquivo de destino e são obrigatórias.'
            );
        }

        return ['key' => $key, 'chain' => $chain];
    }

    /**
     * @param  list<Cell>  $cells
     */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            $value = $cell->getValue();

            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }
}
