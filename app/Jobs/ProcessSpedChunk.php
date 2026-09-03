<?php

namespace App\Jobs;

use App\Models\SpedConversion;
use App\Services\Sped\SpedConverter;
use App\Services\Sped\SpedRecordStream;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Converte um pedaço do lote — os arquivos que cabem numa planilha.
 *
 * Um job por lote, e não um job para o conjunto todo: 199 arquivos levam mais
 * de três horas, e um único job desse tamanho perde tudo se estourar o tempo.
 * Aqui cada pedaço leva minutos, e uma falha custa só aquele pedaço.
 */
class ProcessSpedChunk implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * @param  list<string>  $files
     */
    public function __construct(
        public readonly int $conversionId,
        public readonly int $chunkIndex,
        public readonly array $files,
    ) {}

    public function handle(SpedConverter $converter): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $conversion = SpedConversion::findOrFail($this->conversionId);

        // O primeiro pedaço a rodar tira o lote da fila; os demais não mexem.
        SpedConversion::whereKey($conversion->getKey())
            ->where('status', SpedConversion::STATUS_PENDING)
            ->update(['status' => SpedConversion::STATUS_PROCESSING, 'started_at' => now()]);

        $model = (new SpedRecordStream($this->files[0]))->detectModel();

        $destino = $conversion->workspace_path.'/planilhas';

        if (! is_dir($destino) && ! mkdir($destino, 0700, true) && ! is_dir($destino)) {
            throw new RuntimeException("Não foi possível criar {$destino}.");
        }

        $summary = $converter->toSpreadsheet(
            $this->files,
            $destino,
            model: $model,
            baseName: sprintf('%s-%03d', strtoupper($model->value), $this->chunkIndex + 1),
        );

        // Vários workers escrevem ao mesmo tempo; tudo aqui é incremento atômico.
        SpedConversion::whereKey($conversion->getKey())->update([
            'model' => $model->value,
            'processed_count' => DB::raw('processed_count + '.count($this->files)),
            'row_count' => DB::raw('row_count + '.$summary->totalRows()),
            'sheet_count' => DB::raw('greatest(sheet_count, '.$summary->sheetCount().')'),
        ]);
    }

    /**
     * Se um pedaço morre, o lote inteiro perde o sentido: marca a conversão e
     * deixa o motivo à vista, em vez de ela ficar eternamente "convertendo".
     */
    public function failed(?Throwable $e): void
    {
        SpedConversion::whereKey($this->conversionId)->update([
            'status' => SpedConversion::STATUS_FAILED,
            'error_message' => Str::limit($e?->getMessage() ?? 'A conversão foi interrompida.', 500),
            'finished_at' => now(),
        ]);

        $this->batch()?->cancel();
    }
}
