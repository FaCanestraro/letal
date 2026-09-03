<?php

namespace App\Jobs;

use App\Models\SpedConversion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Fecha a conversão depois que todos os pedaços terminaram: junta as planilhas,
 * guarda o resultado e apaga a área de trabalho.
 */
class FinalizeSpedConversion implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $conversionId) {}

    public function handle(): void
    {
        $conversion = SpedConversion::findOrFail($this->conversionId);
        $workspace = (string) $conversion->workspace_path;

        try {
            // Nunca publicar um lote pela metade como se estivesse pronto: se
            // algum pedaço não chegou ao fim, o resultado sai faltando meses e
            // ninguém tem como perceber olhando a planilha.
            if ($conversion->processed_count < $conversion->input_count) {
                throw new RuntimeException(sprintf(
                    'A conversão parou em %d de %d arquivos. Nenhum resultado foi publicado — '.
                    'reenvie o lote para tentar de novo.',
                    $conversion->processed_count,
                    $conversion->input_count,
                ));
            }

            $planilhas = glob($workspace.'/planilhas/*.xlsx') ?: [];
            sort($planilhas);

            if ($planilhas === []) {
                throw new RuntimeException('Nenhuma planilha foi gerada.');
            }

            $stamp = now()->format('Ymd-His');
            $prefixo = strtoupper($conversion->model?->value ?? 'SPED');
            $unica = count($planilhas) === 1;

            $name = $unica
                ? sprintf('%s-%s.xlsx', $prefixo, $stamp)
                : sprintf('%s-%s-planilhas.zip', $prefixo, $stamp);

            $relative = sprintf('sped/%d/%s-%s', $conversion->user_id, Str::ulid(), $name);
            $destino = $this->prepareDestination($relative);

            // O resultado deste acervo passa de 7 GB. Ler o arquivo para gravá-lo
            // estoura qualquer memory_limit, então nada aqui carrega o conteúdo:
            // a planilha única é movida e o .zip é montado direto no destino.
            if ($unica) {
                $this->moveInto($planilhas[0], $destino);
            } else {
                $this->zip($planilhas, $destino);
            }

            $conversion->forceFill([
                'output_path' => $relative,
                'output_name' => $name,
                'output_size' => Storage::disk('local')->size($relative),
                'status' => SpedConversion::STATUS_DONE,
                'error_message' => null,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $conversion->forceFill([
                'status' => SpedConversion::STATUS_FAILED,
                'error_message' => Str::limit($e->getMessage(), 500),
                'finished_at' => now(),
            ])->save();

            throw $e;
        } finally {
            $this->deleteDirectory($workspace);
            $conversion->forceFill(['workspace_path' => null])->save();
        }
    }

    /**
     * Caminho absoluto do destino, com o diretório já criado.
     */
    private function prepareDestination(string $relative): string
    {
        $absolute = Storage::disk('local')->path($relative);
        $pasta = dirname($absolute);

        if (! is_dir($pasta) && ! mkdir($pasta, 0755, true) && ! is_dir($pasta)) {
            throw new RuntimeException("Não foi possível criar {$pasta}.");
        }

        return $absolute;
    }

    /**
     * Move sem passar pela memória. No mesmo volume é instantâneo; entre
     * volumes cai numa cópia em fluxo.
     */
    private function moveInto(string $origem, string $destino): void
    {
        if (@rename($origem, $destino)) {
            return;
        }

        $entrada = fopen($origem, 'rb');
        $saida = fopen($destino, 'wb');

        if ($entrada === false || $saida === false) {
            throw new RuntimeException('Não foi possível gravar o resultado da conversão.');
        }

        try {
            if (stream_copy_to_stream($entrada, $saida) === false) {
                throw new RuntimeException('Falha ao gravar o resultado da conversão.');
            }
        } finally {
            fclose($entrada);
            fclose($saida);
        }

        @unlink($origem);
    }

    /**
     * @param  list<string>  $files
     */
    private function zip(array $files, string $zipPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível montar o .zip com as planilhas.');
        }

        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();
    }

    private function deleteDirectory(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $item) {
            is_dir($item) ? $this->deleteDirectory($item) : @unlink($item);
        }

        @rmdir($dir);
    }
}
