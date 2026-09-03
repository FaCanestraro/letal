<?php

namespace App\Services\Sped;

use PDO;
use RuntimeException;

/**
 * Reconstrói os arquivos .txt a partir das linhas da planilha.
 *
 * Cada combinação de ID_DT_INI, ID_DT_FIN e ID_CNPJ vira um arquivo. Os
 * registros pais, que na planilha aparecem repetidos em cada linha filha, são
 * reagrupados numa árvore e emitidos uma única vez; os contadores do SPED
 * (9900, X990 e 9999) são recalculados do zero.
 */
final class SpedTextWriter
{
    private readonly string $spoolDir;

    /** @var array<string, resource> */
    private array $handles = [];

    public function __construct(private readonly SpedLayout $layout)
    {
        $this->spoolDir = sprintf('%s/sped-out-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));

        if (! mkdir($this->spoolDir, 0700, true) && ! is_dir($this->spoolDir)) {
            throw new RuntimeException("Não foi possível criar a área temporária {$this->spoolDir}.");
        }
    }

    /**
     * @param  iterable<array{key: array{0: string, 1: string, 2: string}, chain: list<array{reg: string, values: list<string>}>}>  $rows
     * @return list<string>
     */
    public function write(iterable $rows, string $outputDir): array
    {
        try {
            foreach ($rows as $row) {
                $this->spool($row);
            }

            foreach ($this->handles as $handle) {
                fclose($handle);
            }
            $this->handles = [];

            if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
                throw new RuntimeException("Não foi possível criar {$outputDir}.");
            }

            $files = [];

            foreach (glob($this->spoolDir.'/*.jsonl') ?: [] as $spool) {
                $files[] = $this->buildFile($spool, $outputDir);
            }

            sort($files);

            return $files;
        } finally {
            $this->cleanUp();
        }
    }

    public function cleanUp(): void
    {
        foreach ($this->handles as $handle) {
            @fclose($handle);
        }
        $this->handles = [];

        foreach (glob($this->spoolDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->spoolDir);
    }

    /**
     * @param  array{key: array{0: string, 1: string, 2: string}, chain: list<array{reg: string, values: list<string>}>}  $row
     */
    private function spool(array $row): void
    {
        $name = implode('-', $row['key']);
        $handle = $this->handles[$name] ??= $this->openSpool($name);

        fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * Monta um arquivo .txt inteiro: uma escrituração de um CNPJ num período.
     *
     * As linhas passam por um SQLite temporário só para serem ordenadas em
     * disco. Montar a árvore em memória custava mais de 500 MB num arquivo de
     * 400 mil linhas; com a ordenação externa, a montagem vira uma varredura
     * linear e o consumo deixa de depender do tamanho da escrituração.
     */
    private function buildFile(string $spool, string $outputDir): string
    {
        $database = $spool.'.db';
        @unlink($database);

        $pdo = new PDO('sqlite:'.$database);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = OFF');
        $pdo->exec('PRAGMA synchronous = OFF');
        $pdo->exec('CREATE TABLE linhas (ordenacao BLOB NOT NULL, carga TEXT NOT NULL)');

        try {
            $key = $this->loadForSorting($pdo, $spool);

            $path = sprintf(
                '%s/%s-%s-%s-%s.txt',
                rtrim($outputDir, '/'),
                $key[2],
                $key[0],
                $key[1],
                strtoupper($this->layout->model->value),
            );

            $this->emitFile($pdo, $path);

            return $path;
        } finally {
            $pdo = null;
            @unlink($database);
        }
    }

    /**
     * Carrega o spool no SQLite com a chave que reproduz a ordem do arquivo.
     *
     * A chave de um nível é o par (ordem do registro, ordinal do ancestral),
     * e o ordinal é a posição da PRIMEIRA linha que mencionou aquele ancestral.
     * É isso que preserva a ordem em que os pais aparecem na planilha —
     * ordenar pelos valores deles reembaralharia o arquivo.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function loadForSorting(PDO $pdo, string $spool): array
    {
        $ordinals = $this->assignOrdinals($spool);

        $handle = fopen($spool, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Área temporária ilegível ao remontar o arquivo.');
        }

        $insert = $pdo->prepare('INSERT INTO linhas (ordenacao, carga) VALUES (?, ?)');
        $key = null;
        $sequence = 0;

        $pdo->beginTransaction();

        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }

                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $key ??= $row['key'];
                $sequence++;

                $insert->execute([
                    $this->sortKey($row['chain'], $sequence, $ordinals),
                    json_encode($row['chain'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);

                if ($sequence % 2000 === 0) {
                    $pdo->commit();
                    $pdo->beginTransaction();
                }
            }

            $pdo->commit();
        } finally {
            fclose($handle);
        }

        if ($key === null) {
            throw new RuntimeException('Nenhuma linha encontrada para remontar o arquivo.');
        }

        return $key;
    }

    /**
     * Primeira aparição de cada caminho de ancestrais.
     *
     * Guarda só o resumo do caminho e um inteiro — não os valores — para que o
     * consumo acompanhe a quantidade de registros pais, e não o tamanho da
     * escrituração.
     *
     * @return array<string, int>
     */
    private function assignOrdinals(string $spool): array
    {
        $handle = fopen($spool, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Área temporária ilegível ao remontar o arquivo.');
        }

        $ordinals = [];
        $sequence = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }

                $chain = json_decode($line, true, flags: JSON_THROW_ON_ERROR)['chain'];
                $sequence++;
                $path = '';
                $last = array_key_last($chain);

                foreach ($chain as $position => $node) {
                    if ($position === $last) {
                        break;
                    }

                    $path .= "\x01".$node['reg']."\x02".implode("\x03", $node['values']);
                    $digest = md5($path, true);

                    if (! isset($ordinals[$digest])) {
                        $ordinals[$digest] = $sequence;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $ordinals;
    }

    /**
     * @param  list<array{reg: string, values: list<string>}>  $chain
     * @param  array<string, int>  $ordinals
     */
    private function sortKey(array $chain, int $sequence, array $ordinals): string
    {
        $parts = [];
        $path = '';
        $last = array_key_last($chain);

        foreach ($chain as $position => $node) {
            $parts[] = sprintf('%07d', $this->layout->orderIndex($node['reg']));

            if ($position === $last) {
                // A folha se ordena pela própria posição de leitura: duas folhas
                // iguais são dois registros e não podem se fundir.
                $parts[] = sprintf('%012d', $sequence);

                break;
            }

            $path .= "\x01".$node['reg']."\x02".implode("\x03", $node['values']);
            $parts[] = sprintf('%012d', $ordinals[md5($path, true)] ?? $sequence);
        }

        return implode("\x01", $parts);
    }

    /**
     * Varre as linhas já ordenadas e escreve o arquivo, fechando cada bloco no
     * ponto em que ele termina e acrescentando o bloco 9 no fim.
     */
    private function emitFile(PDO $pdo, string $path): void
    {
        $output = fopen($path, 'wb');

        if ($output === false) {
            throw new RuntimeException("Não foi possível escrever {$path}.");
        }

        $counts = [];
        $blockLines = 0;
        $currentBlock = null;
        $totalLines = 0;
        $block9Lines = 0;

        /** @var list<array{reg: string, values: list<string>}> $previous */
        $previous = [];

        $write = function (string $register, array $fields) use (
            $output, &$counts, &$blockLines, &$currentBlock, &$totalLines, &$block9Lines
        ): void {
            $block = $register[0];

            if ($block !== $currentBlock) {
                if ($currentBlock !== null && $currentBlock !== '9') {
                    $closer = $currentBlock.'990';
                    fwrite($output, $this->encode($closer, [(string) ($blockLines + 1)]));
                    $counts[$closer] = ($counts[$closer] ?? 0) + 1;
                    $totalLines++;
                }

                $currentBlock = $block;
                $blockLines = 0;
            }

            fwrite($output, $this->encode($register, $fields));
            $counts[$register] = ($counts[$register] ?? 0) + 1;
            $blockLines++;
            $totalLines++;

            if ($block === '9') {
                $block9Lines++;
            }
        };

        $statement = $pdo->query('SELECT carga FROM linhas ORDER BY ordenacao');

        try {
            while (($carga = $statement->fetchColumn()) !== false) {
                /** @var list<array{reg: string, values: list<string>}> $chain */
                $chain = json_decode((string) $carga, true, flags: JSON_THROW_ON_ERROR);
                $last = array_key_last($chain);

                foreach ($chain as $position => $node) {
                    // Ancestral idêntico ao da linha anterior já foi escrito.
                    $same = $position !== $last
                        && isset($previous[$position])
                        && $previous[$position]['reg'] === $node['reg']
                        && $previous[$position]['values'] === $node['values'];

                    if ($same) {
                        continue;
                    }

                    $write($node['reg'], $this->toTextFields($node['reg'], $node['values']));

                    // Um ancestral novo invalida tudo que vinha abaixo dele.
                    $previous = array_slice($chain, 0, $position + 1);
                }

                $previous = $chain;
            }
        } finally {
            $statement->closeCursor();
        }

        if ($currentBlock !== null && $currentBlock !== '9') {
            $closer = $currentBlock.'990';
            fwrite($output, $this->encode($closer, [(string) ($blockLines + 1)]));
            $counts[$closer] = ($counts[$closer] ?? 0) + 1;
            $totalLines++;
        }

        $this->appendBlock9($output, $counts, $totalLines, $block9Lines);

        fclose($output);
    }

    /**
     * Escreve o bloco 9: abertura quando ela não veio da planilha, uma entrada
     * 9900 por registro do arquivo, o fechamento e o total de linhas.
     *
     * @param  resource  $output
     * @param  array<string, int>  $counts
     */
    private function appendBlock9($output, array $counts, int $totalLines, int $block9Lines): void
    {
        $opener = ! isset($counts['9001']);

        if ($opener) {
            fwrite($output, $this->encode('9001', ['0']));
            $counts['9001'] = 1;
            $totalLines++;
            $block9Lines++;
        }

        // O 9900 tem uma entrada por registro do arquivo, contando a si mesmo.
        $distinct = count($counts) + 3; // 9900, 9990 e 9999 ainda não foram contados
        $counts['9900'] = $distinct;
        $counts['9990'] = 1;
        $counts['9999'] = 1;

        // O acervo mostra duas convenções para a ordem das entradas: alfabética
        // e por bloco. Ambas passam no PVA; adotamos a alfabética, que é a da
        // maioria dos arquivos. A contagem do próprio 9900 fica por último,
        // porque só fecha depois das demais.
        $registers = array_keys($counts);
        sort($registers, SORT_STRING);
        $registers = array_values(array_filter($registers, static fn (string $r) => $r !== '9900'));
        $registers[] = '9900';

        foreach ($registers as $register) {
            fwrite($output, $this->encode('9900', [$register, (string) $counts[$register]]));
        }

        $block9Lines += count($registers);
        $totalLines += count($registers);

        fwrite($output, $this->encode('9990', [(string) ($block9Lines + 2)]));
        fwrite($output, $this->encode('9999', [(string) ($totalLines + 2)]));
    }

    /**
     * @param  list<string>  $fields
     */
    private function encode(string $register, array $fields): string
    {
        return mb_convert_encoding(
            '|'.$register.'|'.implode('|', $fields)."|\r\n",
            'ISO-8859-1',
            'UTF-8',
        );
    }

    /**
     * Devolve os campos na largura e na ordem que o leiaute do .txt espera.
     * Quando o registro tem mais de uma versão, escolhe a mais larga que não
     * deixe nenhum valor preenchido de fora.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function toTextFields(string $register, array $columns): array
    {
        foreach ($this->layout->columnMapCandidates($register) as $candidate) {
            $used = array_flip($candidate['para_coluna']);
            $spill = false;

            foreach ($columns as $index => $value) {
                if ($value !== '' && ! isset($used[$index])) {
                    $spill = true;
                    break;
                }
            }

            if (! $spill) {
                return array_map(fn (int $column) => $columns[$column] ?? '', $candidate['para_coluna']);
            }
        }

        return array_values(array_slice($columns, 0, $this->layout->fieldCount($register)));
    }

    /**
     * @return resource
     */
    private function openSpool(string $name): mixed
    {
        $handle = fopen($this->spoolDir.'/'.preg_replace('/[^0-9A-Za-z-]/', '_', $name).'.jsonl', 'wb');

        if ($handle === false) {
            throw new RuntimeException("Não foi possível abrir a área temporária de {$name}.");
        }

        return $handle;
    }
}
