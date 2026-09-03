<?php

namespace App\Services\Sped;

use App\Enums\ConversionDirection;
use App\Enums\SpedModel;
use App\Models\SpedConversion;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Executa uma conversão já registrada: lê os arquivos que o upload deixou na
 * área de trabalho, produz o resultado e atualiza o registro.
 *
 * Roda dentro da fila. A ECD, o maior caso do acervo, leva cerca de meio minuto.
 */
final class ConversionRunner
{
    public function __construct(private readonly SpedConverter $converter) {}

    public function run(SpedConversion $conversion): void
    {
        $workspace = $conversion->workspace_path;

        if ($workspace === null || ! is_dir($workspace)) {
            $this->fail($conversion, 'Os arquivos enviados não estão mais disponíveis.');

            return;
        }

        $conversion->forceFill([
            'status' => SpedConversion::STATUS_PROCESSING,
            'started_at' => now(),
        ])->save();

        try {
            $inputs = $this->inputs($workspace);

            if ($inputs === []) {
                throw new RuntimeException('Nenhum arquivo encontrado para converter.');
            }

            [$relative, $name, $summary] = $conversion->direction === ConversionDirection::ToSpreadsheet
                ? $this->toSpreadsheet($inputs, $workspace, $conversion->user_id)
                : $this->toText($inputs[0], $workspace, $conversion->user_id);

            $conversion->forceFill([
                'model' => $summary->model,
                'output_path' => $relative,
                'output_name' => $name,
                'output_size' => Storage::disk('local')->size($relative),
                'row_count' => $summary->totalRows(),
                'sheet_count' => $summary->sheetCount(),
                'status' => SpedConversion::STATUS_DONE,
                'error_message' => null,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $this->fail($conversion, $e->getMessage());
        } finally {
            $this->deleteDirectory($workspace);

            $conversion->forceFill(['workspace_path' => null])->save();
        }
    }

    public function fail(SpedConversion $conversion, string $message): void
    {
        $conversion->forceFill([
            'status' => SpedConversion::STATUS_FAILED,
            'error_message' => Str::limit($message, 500),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Os arquivos são gravados com prefixo numérico para preservar a ordem de envio.
     *
     * @return list<string>
     */
    private function inputs(string $workspace): array
    {
        $files = glob($workspace.'/entrada/*') ?: [];

        usort($files, static fn (string $a, string $b) => (int) basename($a) <=> (int) basename($b));

        return array_values($files);
    }

    /**
     * @param  list<string>  $inputs
     * @return array{0: string, 1: string, 2: ConversionSummary}
     */
    private function toSpreadsheet(array $inputs, string $workspace, int $userId): array
    {
        $destino = $workspace.'/planilhas';
        $this->makeDirectory($destino);

        $stamp = now()->format('Ymd-His');
        $model = (new SpedRecordStream($inputs[0]))->detectModel();
        $summary = $this->converter->toSpreadsheet(
            $inputs,
            $destino,
            model: $model,
            baseName: strtoupper($model->value).'-'.$stamp,
        );

        // O volume pode não caber numa planilha só; nesse caso vai um .zip.
        if (count($summary->outputFiles) === 1) {
            $name = basename($summary->outputFiles[0]);

            return [$this->persist($summary->outputFiles[0], $name, $userId), $name, $summary];
        }

        $zipPath = $workspace.'/planilhas.zip';
        $this->zip($summary->outputFiles, $zipPath);
        $name = sprintf('%s-%s-planilhas.zip', strtoupper($summary->model->value), $stamp);

        return [$this->persist($zipPath, $name, $userId), $name, $summary];
    }

    /**
     * @param  list<string>  $files
     */
    private function zip(array $files, string $zipPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível montar o .zip com os resultados.');
        }

        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();
    }

    /**
     * @return array{0: string, 1: string, 2: ConversionSummary}
     */
    private function toText(string $input, string $workspace, int $userId): array
    {
        $model = $this->detectModel($input);
        $summary = $this->converter->toTextFiles($input, $workspace.'/txt', $model);

        $zipPath = $workspace.'/arquivos.zip';
        $this->zip($summary->outputFiles, $zipPath);

        $name = sprintf('%s-txt-%s.zip', strtoupper($model->value), now()->format('Ymd-His'));

        return [$this->persist($zipPath, $name, $userId), $name, $summary];
    }

    /**
     * Descobre o modelo pela planilha.
     *
     * O cabeçalho da aba 0000 é decisivo: cada escrituração declara campos
     * próprios ali. Só quando essa aba falta é que caímos na contagem de abas
     * conhecidas, que é ambígua entre as duas EFD em arquivos pequenos.
     */
    private function detectModel(string $path): SpedModel
    {
        [$openingHeader, $sheets] = $this->inspectWorkbook($path);

        foreach (SpedModel::cases() as $model) {
            $expected = SpedLayout::forModel($model)->fieldNames('0000');

            if ($openingHeader !== [] && array_slice($openingHeader, 0, count($expected)) === $expected) {
                return $model;
            }
        }

        $best = null;
        $bestScore = 0;

        foreach (SpedModel::cases() as $model) {
            $score = count(array_intersect($sheets, SpedLayout::forModel($model)->dataRegisters()));

            if ($score > $bestScore) {
                $best = $model;
                $bestScore = $score;
            }
        }

        if ($best === null) {
            throw new RuntimeException(
                'As abas da planilha não correspondem a nenhum modelo conhecido. '.
                'Use uma planilha gerada por este módulo.'
            );
        }

        return $best;
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function inspectWorkbook(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);

        $sheets = [];
        $openingHeader = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheets[] = $sheet->getName();

                if ($sheet->getName() !== '0000') {
                    continue;
                }

                foreach ($sheet->getRowIterator() as $row) {
                    $header = array_map(static fn ($cell) => (string) $cell->getValue(), $row->cells);
                    // Descarta as três colunas de identificação e a coluna REG.
                    $openingHeader = array_values(array_slice($header, 4));

                    break;
                }
            }
        } finally {
            $reader->close();
        }

        return [$openingHeader, $sheets];
    }

    /**
     * Grava o resultado sem carregá-lo na memória: um .zip de conversão pode
     * passar de vários GB, e ler o arquivo inteiro estoura o memory_limit.
     */
    private function persist(string $absolutePath, string $name, int $userId): string
    {
        $relative = sprintf('sped/%d/%s-%s', $userId, Str::ulid(), $name);
        $destino = Storage::disk('local')->path($relative);
        $pasta = dirname($destino);

        if (! is_dir($pasta) && ! mkdir($pasta, 0755, true) && ! is_dir($pasta)) {
            throw new RuntimeException("Não foi possível criar {$pasta}.");
        }

        if (! @copy($absolutePath, $destino)) {
            throw new RuntimeException('Não foi possível gravar o resultado da conversão.');
        }

        return $relative;
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException("Não foi possível criar {$path}.");
        }
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
