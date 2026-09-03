<?php

namespace Tests\Feature;

use App\Enums\SpedModel;
use App\Services\Sped\SpedConverter;
use App\Services\Sped\SpedLayout;
use DateTimeInterface;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use Tests\TestCase;

class SpedConversionTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/sped-test-'.Str::random(8);
        mkdir($this->workDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->workDir);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Ida: .txt -> planilha
    // ------------------------------------------------------------------

    public function test_it_creates_one_sheet_per_leaf_register(): void
    {
        $txt = $this->writeSampleFile('01012023', '31012023');
        $summary = (new SpedConverter)->toSpreadsheet([$txt], $this->workDir, baseName: 'saida');
        $xlsx = $summary->outputFiles[0];

        $this->assertSame(SpedModel::EfdIcms, $summary->model);
        $this->assertFileExists($xlsx);

        $sheets = $this->readSheets($xlsx);

        // O C100 tem filhos, então não vira aba; o C170 e o 0150 viram.
        $this->assertArrayHasKey('0000', $sheets);
        $this->assertArrayHasKey('0150', $sheets);
        $this->assertArrayHasKey('C170', $sheets);
        $this->assertArrayNotHasKey('C100', $sheets);
        $this->assertArrayNotHasKey('9900', $sheets, 'Contadores não viram aba.');
    }

    public function test_the_sheet_repeats_the_fields_of_every_ancestor(): void
    {
        $xlsx = $this->convertToSingleWorkbook([$this->writeSampleFile('01012023', '31012023')]);

        $header = $this->readSheets($xlsx)['C170'][0];

        $this->assertSame(['ID_DT_INI', 'ID_DT_FIN', 'ID_CNPJ', 'REG'], array_slice($header, 0, 4));
        $this->assertSame('IND_MOV', $header[4], 'Campo do C001.');
        $this->assertSame('REG', $header[5]);
        $this->assertContains('CHV_NFE', $header, 'Campo do C100 repetido na linha do C170.');
        $this->assertContains('NUM_ITEM', $header, 'Campo do próprio C170.');
    }

    public function test_it_keeps_dates_numbers_and_codes_in_the_right_type(): void
    {
        $xlsx = $this->convertToSingleWorkbook([$this->writeSampleFile('01012023', '31012023')]);

        $row = $this->readSheets($xlsx, raw: true)['0000'][1];
        $header = $this->readSheets($xlsx)['0000'][0];
        $value = fn (string $name) => $row[array_search($name, $header, true)];

        $this->assertInstanceOf(DateTimeInterface::class, $value('DT_INI'));
        $this->assertSame('01/01/2023', $value('DT_INI')->format('d/m/Y'));

        // Códigos com zero à esquerda e documentos longos têm de continuar texto.
        $this->assertSame('017', $value('COD_VER'));
        $this->assertSame('22137526000150', $value('CNPJ'));
    }

    public function test_it_converts_decimal_values_to_numbers(): void
    {
        $xlsx = $this->convertToSingleWorkbook([$this->writeSampleFile('01012023', '31012023')]);

        $header = $this->readSheets($xlsx)['C170'][0];
        $row = $this->readSheets($xlsx, raw: true)['C170'][1];

        $this->assertSame(639.95, $row[array_search('VL_DOC', $header, true)]);
    }

    public function test_it_consolidates_several_files_into_one_workbook(): void
    {
        $files = [
            $this->writeSampleFile('01012023', '31012023'),
            $this->writeSampleFile('01022023', '28022023'),
        ];
        $summary = (new SpedConverter)->toSpreadsheet($files, $this->workDir, baseName: 'saida');
        $xlsx = $summary->outputFiles[0];

        $this->assertSame(2, $summary->inputFiles);

        $rows = $this->readSheets($xlsx, raw: true)['0000'];
        $periods = array_map(fn (array $row) => $row[0]->format('d/m/Y'), array_slice($rows, 1));

        $this->assertSame(['01/01/2023', '01/02/2023'], $periods);
    }

    public function test_it_refuses_to_mix_models_in_the_same_batch(): void
    {
        $icms = $this->writeSampleFile('01012023', '31012023');
        $ecd = $this->workDir.'/ecd.txt';
        $this->writeLines($ecd, ['|0000|LECD|01012023|31122023|EMPRESA|22137526000150|MG|1|3106200||||||||||||||']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Converta um modelo de cada vez/');

        (new SpedConverter)->toSpreadsheet([$icms, $ecd], $this->workDir, baseName: 'saida');
    }

    public function test_it_rejects_a_file_without_the_opening_record(): void
    {
        $path = $this->workDir.'/vazio.txt';
        $this->writeLines($path, ['|0150|FOR001|FORNECEDOR|1058|']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/identificar o modelo/');

        (new SpedConverter)->toSpreadsheet([$path], $this->workDir, baseName: 'saida');
    }

    public function test_it_splits_into_several_workbooks_when_a_sheet_would_overflow(): void
    {
        $files = [
            $this->writeSampleFile('01012023', '31012023'),
            $this->writeSampleFile('01022023', '28022023'),
            $this->writeSampleFile('01032023', '31032023'),
        ];

        // Teto artificial de uma linha por aba: cada arquivo tem de ir sozinho.
        $summary = (new SpedConverter)->toSpreadsheet(
            $files, $this->workDir, baseName: 'lote', maxRowsPerSheet: 1,
        );

        $this->assertCount(3, $summary->outputFiles);
        $this->assertSame('lote-parte-01.xlsx', basename($summary->outputFiles[0]));
        $this->assertSame('lote-parte-03.xlsx', basename($summary->outputFiles[2]));

        // Cada planilha guarda a competência do seu arquivo, e nada se perde.
        $periodos = [];

        foreach ($summary->outputFiles as $arquivo) {
            $linhas = $this->readSheets($arquivo, raw: true)['0000'];
            $this->assertCount(2, $linhas, 'Cabeçalho e uma competência.');
            $periodos[] = $linhas[1][0]->format('d/m/Y');
        }

        $this->assertSame(['01/01/2023', '01/02/2023', '01/03/2023'], $periodos);
    }

    public function test_a_single_file_too_big_for_one_sheet_continues_on_the_next(): void
    {
        $layout = SpedLayout::forModel(SpedModel::EfdIcms);
        $path = $this->workDir.'/muitos.txt';

        // Três 0150 no mesmo arquivo, com teto de duas linhas por aba.
        $this->writeLines($path, [
            $this->line($layout, '0000', ['COD_VER' => '017', 'COD_FIN' => '0', 'DT_INI' => '01012023',
                'DT_FIN' => '31012023', 'NOME' => 'EMPRESA', 'CNPJ' => '22137526000150', 'UF' => 'MG']),
            $this->line($layout, '0001', ['IND_MOV' => '0']),
            $this->line($layout, '0150', ['COD_PART' => 'A', 'NOME' => 'UM', 'COD_PAIS' => '1058']),
            $this->line($layout, '0150', ['COD_PART' => 'B', 'NOME' => 'DOIS', 'COD_PAIS' => '1058']),
            $this->line($layout, '0150', ['COD_PART' => 'C', 'NOME' => 'TRES', 'COD_PAIS' => '1058']),
        ]);

        $summary = (new SpedConverter)->toSpreadsheet(
            [$path], $this->workDir, baseName: 'unico', maxRowsPerSheet: 2,
        );

        $this->assertCount(1, $summary->outputFiles, 'Um arquivo sozinho não pode ser dividido em planilhas.');

        $sheets = $this->readSheets($summary->outputFiles[0]);

        $this->assertArrayHasKey('0150', $sheets);
        $this->assertArrayHasKey('0150-2', $sheets, 'A aba continua numa segunda quando estoura o teto.');
        $this->assertCount(3, $sheets['0150'], 'Cabeçalho e duas linhas.');
        $this->assertCount(2, $sheets['0150-2'], 'Cabeçalho e a linha restante.');
    }

    // ------------------------------------------------------------------
    // Volta: planilha -> .txt
    // ------------------------------------------------------------------

    public function test_the_round_trip_preserves_every_data_record(): void
    {
        $original = $this->writeSampleFile('01012023', '31012023');
        $xlsx = $this->convertToSingleWorkbook([$original]);
        $converter = new SpedConverter;

        $summary = $converter->toTextFiles($xlsx, $this->workDir.'/volta', SpedModel::EfdIcms);

        $this->assertCount(1, $summary->outputFiles);

        $this->assertSame(
            $this->dataRecords($original),
            $this->dataRecords($summary->outputFiles[0]),
        );
    }

    public function test_the_round_trip_keeps_accented_text_in_latin1(): void
    {
        $original = $this->writeSampleFile('01012023', '31012023', name: 'CONSTRUÇÃO E MANUTENÇÃO LTDA');
        $xlsx = $this->convertToSingleWorkbook([$original]);
        $converter = new SpedConverter;

        $out = $converter->toTextFiles($xlsx, $this->workDir.'/volta', SpedModel::EfdIcms)->outputFiles[0];

        $content = mb_convert_encoding((string) file_get_contents($out), 'UTF-8', 'ISO-8859-1');

        $this->assertStringContainsString('CONSTRUÇÃO E MANUTENÇÃO LTDA', $content);
        $this->assertStringNotContainsString('?', $content);
    }

    public function test_it_rebuilds_the_sped_counters(): void
    {
        $original = $this->writeSampleFile('01012023', '31012023');
        $xlsx = $this->convertToSingleWorkbook([$original]);
        $converter = new SpedConverter;

        $out = $converter->toTextFiles($xlsx, $this->workDir.'/volta', SpedModel::EfdIcms)->outputFiles[0];

        $lines = $this->records($out);
        $count = fn (string $register) => count(array_filter($lines, fn (array $l) => $l[0] === $register));

        // Fechamento de bloco conta as linhas do próprio bloco, inclusive a dele.
        $block0 = count(array_filter($lines, fn (array $l) => $l[0][0] === '0'));
        $this->assertSame((string) $block0, $this->fieldOf($lines, '0990', 0));

        $this->assertSame((string) count($lines), $this->fieldOf($lines, '9999', 0));
        $this->assertSame('1', (string) $count('9990'));

        // O 9900 tem uma entrada por registro do arquivo, contando a si mesmo.
        $distinct = count(array_unique(array_column($lines, 0)));
        $this->assertSame($distinct, $count('9900'));
        $this->assertSame((string) $distinct, $this->fieldOf($lines, '9900', 1, forRegister: '9900'));
    }

    public function test_it_splits_the_workbook_back_into_one_file_per_period(): void
    {
        $files = [
            $this->writeSampleFile('01012023', '31012023'),
            $this->writeSampleFile('01022023', '28022023'),
        ];
        $xlsx = $this->convertToSingleWorkbook($files);
        $converter = new SpedConverter;

        $out = $converter->toTextFiles($xlsx, $this->workDir.'/volta', SpedModel::EfdIcms);

        $this->assertCount(2, $out->outputFiles);

        $names = array_map('basename', $out->outputFiles);
        sort($names);

        $this->assertSame([
            '22137526000150-01012023-31012023-EFD-ICMS.txt',
            '22137526000150-01022023-28022023-EFD-ICMS.txt',
        ], $names);
    }

    public function test_repeated_leaf_records_are_not_collapsed(): void
    {
        $layout = SpedLayout::forModel(SpedModel::EfdIcms);
        $path = $this->workDir.'/repetido.txt';

        // Dois 0150 idênticos: são dois registros, não um.
        $this->writeLines($path, [
            $this->line($layout, '0000', ['COD_VER' => '017', 'COD_FIN' => '0', 'DT_INI' => '01012023',
                'DT_FIN' => '31012023', 'NOME' => 'EMPRESA', 'CNPJ' => '22137526000150', 'UF' => 'MG']),
            $this->line($layout, '0001', ['IND_MOV' => '0']),
            $this->line($layout, '0150', ['COD_PART' => 'FOR001', 'NOME' => 'IGUAL', 'COD_PAIS' => '1058']),
            $this->line($layout, '0150', ['COD_PART' => 'FOR001', 'NOME' => 'IGUAL', 'COD_PAIS' => '1058']),
        ]);

        $xlsx = $this->convertToSingleWorkbook([$path]);
        $converter = new SpedConverter;

        $this->assertCount(3, $this->readSheets($xlsx)['0150'], 'Cabeçalho e as duas linhas.');

        $out = $converter->toTextFiles($xlsx, $this->workDir.'/volta', SpedModel::EfdIcms)->outputFiles[0];
        $lines = $this->records($out);

        $this->assertCount(2, array_filter($lines, fn (array $l) => $l[0] === '0150'));
    }

    // ------------------------------------------------------------------
    // Apoio
    // ------------------------------------------------------------------

    /**
     * @param  list<string>  $files
     */
    private function convertToSingleWorkbook(array $files, string $base = 'saida'): string
    {
        $summary = (new SpedConverter)->toSpreadsheet($files, $this->workDir, baseName: $base);

        $this->assertCount(1, $summary->outputFiles, 'Esta amostra cabe numa planilha só.');

        return $summary->outputFiles[0];
    }

    private function writeSampleFile(string $start, string $end, string $name = 'EMPRESA TESTE LTDA'): string
    {
        $layout = SpedLayout::forModel(SpedModel::EfdIcms);
        $path = $this->workDir.'/'.$start.'.txt';

        $this->writeLines($path, [
            $this->line($layout, '0000', [
                'COD_VER' => '017', 'COD_FIN' => '0', 'DT_INI' => $start, 'DT_FIN' => $end,
                'NOME' => $name, 'CNPJ' => '22137526000150', 'UF' => 'MG',
                'IE' => '0025339560074', 'COD_MUN' => '3106200', 'IND_PERFIL' => 'B', 'IND_ATIV' => '0',
            ]),
            $this->line($layout, '0001', ['IND_MOV' => '0']),
            $this->line($layout, '0150', [
                'COD_PART' => 'FOR001', 'NOME' => 'FORNECEDOR UM', 'COD_PAIS' => '1058',
                'CNPJ' => '02558157047126', 'IE' => '0621904680045', 'COD_MUN' => '3118601',
            ]),
            $this->line($layout, 'B001', ['IND_DAD' => '1']),
            $this->line($layout, 'C001', ['IND_MOV' => '0']),
            $this->line($layout, 'C100', [
                'IND_OPER' => '0', 'IND_EMIT' => '1', 'COD_PART' => 'FOR001', 'COD_MOD' => '55',
                'COD_SIT' => '00', 'SER' => '013', 'NUM_DOC' => '278185',
                'CHV_NFE' => '25230147960950090449550130002781851010978066',
                'DT_DOC' => '30012023', 'DT_E_S' => '30012023', 'VL_DOC' => '639,95',
                'IND_PGTO' => '0', 'VL_MERC' => '639,95',
            ]),
            $this->line($layout, 'C170', [
                'NUM_ITEM' => '1', 'COD_ITEM' => 'ITEM1', 'QTD' => '4', 'UNID' => '1',
                'VL_ITEM' => '639,95', 'IND_MOV' => '0', 'CST_ICMS' => '060', 'CFOP' => '1407',
                'COD_NAT' => '104', 'COD_CTA' => '34518',
            ]),
        ]);

        return $path;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function line(SpedLayout $layout, string $register, array $values): string
    {
        $fields = array_fill(0, $layout->fieldCount($register), '');

        foreach ($layout->fieldNames($register) as $index => $name) {
            if (array_key_exists($name, $values)) {
                $fields[$index] = $values[$name];
            }
        }

        return '|'.$register.'|'.implode('|', $fields).'|';
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeLines(string $path, array $lines): void
    {
        file_put_contents($path, mb_convert_encoding(implode("\r\n", $lines)."\r\n", 'ISO-8859-1', 'UTF-8'));
    }

    /**
     * @return list<array{0: string, 1: list<string>}>
     */
    private function records(string $path): array
    {
        $content = mb_convert_encoding((string) file_get_contents($path), 'UTF-8', 'ISO-8859-1');
        $records = [];

        foreach (explode("\r\n", $content) as $line) {
            if (preg_match('/^\|([0-9A-Z][0-9]{3})\|/', $line, $m) === 1) {
                $fields = explode('|', $line);
                $records[] = [$m[1], array_slice($fields, 2, count($fields) - 3)];
            }
        }

        return $records;
    }

    /**
     * Só os registros de dados: sem contadores nem fechamento de bloco.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function dataRecords(string $path): array
    {
        $layout = SpedLayout::forModel(SpedModel::EfdIcms);

        return array_values(array_filter(
            $this->records($path),
            fn (array $record) => $layout->hasRegister($record[0]),
        ));
    }

    /**
     * @param  list<array{0: string, 1: list<string>}>  $lines
     */
    private function fieldOf(array $lines, string $register, int $index, ?string $forRegister = null): string
    {
        foreach ($lines as [$code, $fields]) {
            if ($code !== $register) {
                continue;
            }

            if ($forRegister !== null && ($fields[0] ?? null) !== $forRegister) {
                continue;
            }

            return $fields[$index] ?? '';
        }

        return '';
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private function readSheets(string $path, bool $raw = false): array
    {
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];

                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(
                        fn ($cell) => $raw ? $cell->getValue() : (is_scalar($v = $cell->getValue()) ? (string) $v : $v),
                        $row->cells,
                    );
                }

                $sheets[$sheet->getName()] = $rows;
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $item) {
            is_dir($item) ? $this->deleteDirectory($item) : @unlink($item);
        }

        @rmdir($dir);
    }
}
