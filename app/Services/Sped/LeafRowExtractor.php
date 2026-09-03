<?php

namespace App\Services\Sped;

use Generator;
use RuntimeException;

/**
 * Transforma o fluxo de registros de um arquivo nas linhas que vão para a
 * planilha: cada registro sem filhos vira uma linha, repetindo à esquerda os
 * campos de todos os seus ancestrais.
 */
final class LeafRowExtractor
{
    public function __construct(private readonly SpedLayout $layout) {}

    /**
     * @return Generator<int, LeafRow>
     */
    public function extract(SpedRecordStream $stream): Generator
    {
        /** @var list<array{reg: string, values: list<string>, hasChild: bool}> $stack */
        $stack = [];
        $key = null;

        foreach ($stream->records() as [$register, $values]) {
            if (! $this->layout->hasRegister($register)) {
                continue; // contadores e registros de encerramento
            }

            if ($register === '0000') {
                $key = $this->openingKey($values);
            }

            $parent = $this->layout->parent($register);

            while ($stack !== [] && end($stack)['reg'] !== $parent) {
                $leaf = array_pop($stack);

                if (! $leaf['hasChild'] && ! $this->layout->isStructural($leaf['reg'])) {
                    yield $this->makeRow($leaf, $stack, $key);
                }
            }

            if ($parent !== null && $stack !== []) {
                $stack[array_key_last($stack)]['hasChild'] = true;
            }

            $stack[] = ['reg' => $register, 'values' => $values, 'hasChild' => false];
        }

        while ($stack !== []) {
            $leaf = array_pop($stack);

            if (! $leaf['hasChild'] && ! $this->layout->isStructural($leaf['reg'])) {
                yield $this->makeRow($leaf, $stack, $key);
            }
        }
    }

    /**
     * @param  array{reg: string, values: list<string>, hasChild: bool}  $leaf
     * @param  list<array{reg: string, values: list<string>, hasChild: bool}>  $ancestors
     * @param  null|array{0: string, 1: string, 2: string}  $key
     */
    private function makeRow(array $leaf, array $ancestors, ?array $key): LeafRow
    {
        if ($key === null) {
            throw new RuntimeException('O arquivo não trouxe o registro 0000 antes dos demais.');
        }

        $cells = $key;

        foreach ([...$ancestors, $leaf] as $node) {
            $cells[] = $node['reg'];

            foreach ($this->placeFields($node['reg'], $node['values']) as $value) {
                $cells[] = $value;
            }
        }

        return new LeafRow($leaf['reg'], $cells);
    }

    /**
     * Distribui os campos do .txt nas colunas do registro. Na ECD e na ECF
     * alguns registros mudaram de ordem entre versões do leiaute, e aí o mapa
     * do layout diz para onde vai cada campo.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private function placeFields(string $register, array $values): array
    {
        $width = $this->layout->fieldCount($register);
        $placed = array_fill(0, $width, '');
        $map = $this->layout->columnMap($register, count($values));

        foreach ($values as $index => $value) {
            $column = $map[$index] ?? $index;

            if ($column < $width) {
                $placed[$column] = $value;
            }
        }

        return $placed;
    }

    /**
     * @param  list<string>  $values
     * @return array{0: string, 1: string, 2: string}
     */
    private function openingKey(array $values): array
    {
        $indexes = $this->layout->openingKeyIndexes();

        return [
            $values[$indexes['dt_ini']] ?? '',
            $values[$indexes['dt_fin']] ?? '',
            $values[$indexes['cnpj']] ?? '',
        ];
    }
}
