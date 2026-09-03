<?php

namespace App\Services\Sped;

use App\Enums\SpedModel;
use RuntimeException;

/**
 * Layout de um modelo de escrituração: hierarquia dos registros, nome e tipo
 * de cada campo, ordem dos registros no arquivo e, quando o leiaute do .txt
 * não bate posicionalmente com as colunas da planilha (ECD e ECF), o mapa de
 * campo-do-txt para coluna.
 */
final class SpedLayout
{
    /** @var array<string, self> */
    private static array $cache = [];

    /**
     * @param  array<string, array{pai: ?string, campos: list<array{nome: string, tipo: string}>}>  $registers
     * @param  list<string>  $order
     * @param  array<string, array{campos_txt: int, para_coluna: list<int>}>  $columnMaps
     * @param  list<string>  $structural
     */
    private function __construct(
        public readonly SpedModel $model,
        private readonly array $registers,
        private readonly array $order,
        private readonly array $columnMaps,
        private readonly array $structural,
    ) {}

    public static function forModel(SpedModel $model): self
    {
        return self::$cache[$model->value] ??= self::load($model);
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }

    private static function load(SpedModel $model): self
    {
        $path = $model->layoutPath();

        if (! is_file($path)) {
            throw new RuntimeException("Layout não encontrado para {$model->value}: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return new self(
            model: $model,
            registers: $data['registros'],
            order: $data['ordem_registros'] ?? array_keys($data['registros']),
            columnMaps: $data['ordem_campos'] ?? [],
            structural: $data['registros_estruturais'] ?? [],
        );
    }

    public function hasRegister(string $code): bool
    {
        return isset($this->registers[$code]);
    }

    /**
     * @return list<array{nome: string, tipo: string}>
     */
    public function fields(string $code): array
    {
        return $this->registers[$code]['campos'] ?? [];
    }

    /**
     * @return list<string>
     */
    public function fieldNames(string $code): array
    {
        return array_column($this->fields($code), 'nome');
    }

    public function fieldCount(string $code): int
    {
        return count($this->fields($code));
    }

    /**
     * Registros que só existem para abrir bloco e nunca viram aba própria,
     * mesmo quando aparecem sem filhos — caso do 9001 na ECF.
     */
    public function isStructural(string $code): bool
    {
        return in_array($code, $this->structural, strict: true);
    }

    public function parent(string $code): ?string
    {
        return $this->registers[$code]['pai'] ?? null;
    }

    /**
     * Cadeia da raiz até o próprio registro, ex.: C001 → C100 → C170.
     *
     * @return list<string>
     */
    public function ancestry(string $code): array
    {
        $chain = [];

        for ($current = $code; $current !== null; $current = $this->parent($current)) {
            array_unshift($chain, $current);
        }

        return $chain;
    }

    /**
     * Posição do registro na ordem exigida pelo arquivo.
     *
     * O SPED ordena por bloco — 0, depois A a Z, depois 1 e 9 — e, dentro do
     * bloco, pelo número do próprio registro. É mais confiável do que a ordem
     * observada nos exemplos: um registro opcional pode simplesmente não ter
     * aparecido no primeiro arquivo lido.
     */
    public function orderIndex(string $code): int
    {
        $block = $code[0];

        $rank = match (true) {
            $block === '0' => 0,
            $block === '1' => 27,
            $block === '9' => 28,
            default => ord($block) - 64,
        };

        return $rank * 10_000 + (int) substr($code, 1);
    }

    /**
     * @return list<string>
     */
    public function registerOrder(): array
    {
        return $this->order;
    }

    /**
     * @return list<string>
     */
    public function dataRegisters(): array
    {
        return array_keys($this->registers);
    }

    /**
     * Onde cada campo do .txt cai nas colunas da planilha. Devolve null quando
     * o mapeamento é posicional (o caso das duas EFD e da maioria dos
     * registros da ECD e da ECF).
     *
     * @return null|list<int>
     */
    public function columnMap(string $code, int $txtFieldCount): ?array
    {
        $rule = $this->columnMaps["{$code}#v{$txtFieldCount}"] ?? $this->columnMaps[$code] ?? null;

        if ($rule === null || $rule['campos_txt'] !== $txtFieldCount) {
            return null;
        }

        return $rule['para_coluna'];
    }

    /**
     * Todos os mapas de coluna conhecidos para um registro, do leiaute mais
     * largo para o mais estreito. Usado no caminho inverso, onde é preciso
     * descobrir qual versão do leiaute reproduz a linha.
     *
     * @return list<array{campos_txt: int, para_coluna: list<int>}>
     */
    public function columnMapCandidates(string $code): array
    {
        $found = [];

        foreach ($this->columnMaps as $key => $rule) {
            if ($key === $code || str_starts_with($key, $code.'#v')) {
                $found[] = $rule;
            }
        }

        usort($found, static fn (array $a, array $b) => $b['campos_txt'] <=> $a['campos_txt']);

        return $found;
    }

    /**
     * Índice dos campos que identificam o arquivo de origem no cabeçalho.
     *
     * @return array{dt_ini: int, dt_fin: int, cnpj: int}
     */
    public function openingKeyIndexes(): array
    {
        $names = $this->fieldNames('0000');

        $find = static function (string $name) use ($names): int {
            $index = array_search($name, $names, strict: true);

            if ($index === false) {
                throw new RuntimeException("Campo {$name} não existe no registro 0000.");
            }

            return $index;
        };

        return ['dt_ini' => $find('DT_INI'), 'dt_fin' => $find('DT_FIN'), 'cnpj' => $find('CNPJ')];
    }
}
