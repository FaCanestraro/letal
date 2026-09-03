<?php

namespace App\Jobs;

use App\Models\SpedConversion;
use App\Services\Sped\ConversionRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Executa a conversão fora da requisição. A ECD do acervo do cliente leva cerca
 * de meio minuto, tempo demais para segurar o navegador.
 */
class ProcessSpedConversion implements ShouldQueue
{
    use Queueable;

    /**
     * Uma tentativa só: reprocessar um arquivo que já falhou dá o mesmo erro,
     * e a mensagem original é o que interessa para o usuário.
     *
     * O tempo é generoso porque um lote de centenas de arquivos leva minutos —
     * a fila existe justamente para isso.
     */
    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(public readonly SpedConversion $conversion) {}

    public function handle(ConversionRunner $runner): void
    {
        $runner->run($this->conversion);
    }

    /**
     * Chamado quando o próprio job morre — estouro de tempo, por exemplo.
     */
    public function failed(?Throwable $e): void
    {
        app(ConversionRunner::class)->fail(
            $this->conversion,
            $e?->getMessage() ?? 'A conversão foi interrompida.',
        );
    }
}
