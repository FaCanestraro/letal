<?php

namespace App\Services\Sped;

use App\Enums\SpedModel;
use RuntimeException;

/**
 * Ponto de entrada do módulo: converte um lote de arquivos .txt do SPED em
 * uma planilha, e a planilha de volta em arquivos .txt.
 */
final class SpedConverter
{
    /**
     * Converte um ou mais .txt do mesmo modelo numa única planilha, que os
     * consolida pelas colunas ID_DT_INI, ID_DT_FIN e ID_CNPJ.
     *
     * @param  list<string>  $txtPaths
     */
    public function toSpreadsheet(array $txtPaths, string $outputDir, ?SpedModel $model = null, string $baseName = 'planilha', ?int $maxRowsPerSheet = null): ConversionSummary
    {
        if ($txtPaths === []) {
            throw new RuntimeException('Envie ao menos um arquivo .txt para converter.');
        }

        $streams = array_map(fn (string $path) => new SpedRecordStream($path), $txtPaths);
        $model ??= $streams[0]->detectModel();

        foreach ($streams as $index => $stream) {
            $found = $stream->detectModel();

            if ($found !== $model) {
                throw new RuntimeException(sprintf(
                    'O arquivo %s é %s, mas o lote é %s. Converta um modelo de cada vez.',
                    basename($txtPaths[$index]),
                    $found->shortLabel(),
                    $model->shortLabel(),
                ));
            }
        }

        $layout = SpedLayout::forModel($model);
        $extractor = new LeafRowExtractor($layout);
        $writer = new SpedWorkbookWriter($layout, $maxRowsPerSheet);

        try {
            foreach ($streams as $index => $stream) {
                $writer->startFile($index);

                foreach ($extractor->extract($stream) as $row) {
                    $writer->add($row);
                }
            }

            $counts = $writer->rowCounts();
            $files = $writer->save($outputDir, $baseName);
        } catch (\Throwable $e) {
            $writer->cleanUp();

            throw $e;
        }

        return new ConversionSummary(
            model: $model,
            inputFiles: count($txtPaths),
            rowsByRegister: $counts ?? $writer->rowCounts(),
            outputFiles: $files,
        );
    }

    /**
     * Converte a planilha de volta em arquivos .txt — um por CNPJ e período,
     * com os contadores do SPED recalculados.
     */
    public function toTextFiles(string $spreadsheetPath, string $outputDir, SpedModel $model): ConversionSummary
    {
        $layout = SpedLayout::forModel($model);
        $reader = new SpedWorkbookReader($layout);
        $writer = new SpedTextWriter($layout);

        $files = $writer->write($reader->read($spreadsheetPath), $outputDir);

        return new ConversionSummary(
            model: $model,
            inputFiles: 1,
            rowsByRegister: [],
            outputFiles: $files,
        );
    }
}
