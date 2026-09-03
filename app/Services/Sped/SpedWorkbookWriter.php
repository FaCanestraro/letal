<?php

namespace App\Services\Sped;

use JsonException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Monta a planilha: uma aba por registro, com as linhas acumuladas de todos
 * os arquivos convertidos na mesma operação.
 *
 * As linhas são despejadas em arquivos temporários enquanto os .txt são lidos
 * e só depois transformadas em células. É o que permite converter uma ECD com
 * mais de cem mil linhas numa aba sem carregar tudo em memória.
 */
final class SpedWorkbookWriter
{
    /**
     * Teto de linhas de uma aba no Excel, menos a linha de cabeçalho.
     * Passar disso gera um arquivo que o Excel simplesmente se recusa a abrir.
     */
    public const MAX_ROWS_PER_SHEET = 1_048_575;

    private readonly string $spoolDir;

    /** @var array<string, resource> */
    private array $handles = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<int, array<string, int>> linhas por arquivo de origem e registro */
    private array $countsByFile = [];

    private int $currentFile = 0;

    /**
     * @param  null|int  $maxRowsPerSheet  Teto de linhas por aba; os testes o reduzem
     *                                     para exercitar a divisão sem gerar milhões de linhas.
     */
    public function __construct(
        private readonly SpedLayout $layout,
        private readonly ?int $maxRowsPerSheet = null,
    ) {
        $this->spoolDir = sprintf('%s/sped-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));

        if (! mkdir($this->spoolDir, 0700, true) && ! is_dir($this->spoolDir)) {
            throw new RuntimeException("Não foi possível criar a área temporária {$this->spoolDir}.");
        }
    }

    /**
     * Marca de qual arquivo de origem vêm as próximas linhas. É por arquivo que
     * o lote é dividido quando não cabe numa planilha só.
     */
    private function rowCap(): int
    {
        return $this->maxRowsPerSheet ?? self::MAX_ROWS_PER_SHEET;
    }

    public function startFile(int $index): void
    {
        $this->currentFile = $index;
        $this->countsByFile[$index] ??= [];
    }

    public function add(LeafRow $row): void
    {
        $key = $this->currentFile.'-'.$row->register;
        $handle = $this->handles[$key] ??= $this->openSpool($key);

        fwrite($handle, json_encode($row->cells, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

        $this->counts[$row->register] = ($this->counts[$row->register] ?? 0) + 1;
        $this->countsByFile[$this->currentFile][$row->register] =
            ($this->countsByFile[$this->currentFile][$row->register] ?? 0) + 1;
    }

    /**
     * Divide os arquivos de origem em grupos que caibam numa planilha.
     *
     * Um arquivo nunca é partido ao meio: o corte acontece entre arquivos, para
     * que cada planilha continue sendo um conjunto inteiro de competências.
     *
     * @return list<list<int>>
     */
    public function planWorkbooks(): array
    {
        $groups = [];
        $current = [];
        $running = [];

        foreach (array_keys($this->countsByFile) as $file) {
            $wouldOverflow = false;

            foreach ($this->countsByFile[$file] as $register => $rows) {
                if (($running[$register] ?? 0) + $rows > $this->rowCap()) {
                    $wouldOverflow = true;
                    break;
                }
            }

            if ($wouldOverflow && $current !== []) {
                $groups[] = $current;
                $current = [];
                $running = [];
            }

            $current[] = $file;

            foreach ($this->countsByFile[$file] as $register => $rows) {
                $running[$register] = ($running[$register] ?? 0) + $rows;
            }
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups === [] ? [[]] : $groups;
    }

    /**
     * @return array<string, int>
     */
    public function rowCounts(): array
    {
        return $this->counts;
    }

    /**
     * Escreve as planilhas do lote. Devolve um caminho por planilha: mais de um
     * quando o volume não cabe num arquivo só.
     *
     * @return list<string>
     */
    public function save(string $directory, string $baseName): array
    {
        foreach ($this->handles as $handle) {
            fclose($handle);
        }
        $this->handles = [];

        $groups = $this->planWorkbooks();
        $written = [];

        try {
            foreach ($groups as $position => $files) {
                $path = count($groups) === 1
                    ? sprintf('%s/%s.xlsx', rtrim($directory, '/'), $baseName)
                    : sprintf('%s/%s-parte-%02d.xlsx', rtrim($directory, '/'), $baseName, $position + 1);

                $this->writeWorkbook($path, $files);
                $written[] = $path;
            }
        } finally {
            $this->cleanUp();
        }

        return $written;
    }

    /**
     * @param  list<int>  $files
     */
    private function writeWorkbook(string $path, array $files): void
    {
        $registers = [];

        foreach ($files as $file) {
            foreach (array_keys($this->countsByFile[$file] ?? []) as $register) {
                $registers[$register] = true;
            }
        }

        $registers = array_keys($registers);
        usort($registers, fn (string $a, string $b) => $this->layout->orderIndex($a) <=> $this->layout->orderIndex($b));

        $writer = new Writer;
        $writer->openToFile($path);

        $dateStyle = (new Style)->withFormat('dd/mm/yyyy');
        $headerStyle = new Style(fontBold: true);

        try {
            $first = true;

            foreach ($registers as $register) {
                $columns = $this->columns($register);
                $header = Row::fromValuesWithStyle(array_column($columns, 'nome'), $headerStyle);
                $rowsInSheet = 0;
                $part = 1;

                $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $first = false;
                $sheet->setName($register);
                $writer->addRow($header);

                foreach ($files as $file) {
                    foreach ($this->readSpool($file.'-'.$register) as $cells) {
                        // Nem um arquivo sozinho cabe? A aba continua na seguinte.
                        if ($rowsInSheet >= $this->rowCap()) {
                            $part++;
                            $sheet = $writer->addNewSheetAndMakeItCurrent();
                            $sheet->setName($register.'-'.$part);
                            $writer->addRow($header);
                            $rowsInSheet = 0;
                        }

                        $writer->addRow(new Row($this->toCells($cells, $columns, $dateStyle)));
                        $rowsInSheet++;
                    }
                }
            }
        } finally {
            $writer->close();
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
     * Colunas da aba: a chave do arquivo de origem, seguida dos campos de cada
     * ancestral, cada bloco aberto por sua própria coluna REG.
     *
     * @return list<array{nome: string, tipo: string}>
     */
    public function columns(string $register): array
    {
        $columns = [
            ['nome' => 'ID_DT_INI', 'tipo' => 'data'],
            ['nome' => 'ID_DT_FIN', 'tipo' => 'data'],
            ['nome' => 'ID_CNPJ', 'tipo' => 'texto'],
        ];

        foreach ($this->layout->ancestry($register) as $ancestor) {
            $columns[] = ['nome' => 'REG', 'tipo' => 'texto'];

            foreach ($this->layout->fields($ancestor) as $field) {
                $columns[] = ['nome' => $field['nome'], 'tipo' => $field['tipo']];
            }
        }

        return $columns;
    }

    /**
     * @param  list<string>  $cells
     * @param  list<array{nome: string, tipo: string}>  $columns
     * @return list<Cell>
     */
    private function toCells(array $cells, array $columns, Style $dateStyle): array
    {
        $out = [];

        foreach ($columns as $index => $column) {
            $value = SpedValue::toCell($cells[$index] ?? '', $column['tipo']);

            $out[] = match (true) {
                $value === null => new EmptyCell(null),
                is_float($value) => new NumericCell($value),
                is_string($value) => new StringCell($value),
                default => new DateTimeCell($value, $dateStyle),
            };
        }

        return $out;
    }

    /**
     * @return \Generator<int, list<string>>
     */
    private function readSpool(string $key): \Generator
    {
        if (! is_file($this->spoolPath($key))) {
            return;
        }

        $handle = fopen($this->spoolPath($key), 'rb');

        if ($handle === false) {
            throw new RuntimeException("Área temporária {$key} não pôde ser lida.");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }

                try {
                    yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new RuntimeException("Linha corrompida na área temporária {$key}.", previous: $e);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function openSpool(string $key)
    {
        $handle = fopen($this->spoolPath($key), 'wb');

        if ($handle === false) {
            throw new RuntimeException("Não foi possível abrir a área temporária {$key}.");
        }

        return $handle;
    }

    private function spoolPath(string $key): string
    {
        return $this->spoolDir.'/'.$key.'.jsonl';
    }
}
