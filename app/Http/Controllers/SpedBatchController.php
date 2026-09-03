<?php

namespace App\Http\Controllers;

use App\Enums\ConversionDirection;
use App\Jobs\FinalizeSpedConversion;
use App\Jobs\ProcessSpedChunk;
use App\Jobs\ProcessSpedConversion;
use App\Models\SpedConversion;
use App\Services\Sped\ChunkPlanner;
use Illuminate\Bus\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * Envio em lote, um arquivo por requisição.
 *
 * Mandar tudo num POST só não escala: o PHP lê o corpo inteiro na memória
 * (Request::getContent), então 500 MB de anexos já estouram o memory_limit
 * antes de qualquer validação. Aqui cada arquivo é uma requisição pequena, o
 * envio tem progresso e um arquivo que falha não derruba o lote inteiro.
 */
class SpedBatchController extends Controller
{
    public const MAX_FILES = 400;

    public const MAX_FILE_MB = 256;

    /**
     * Abre o lote e devolve o identificador que recebe os arquivos.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'direction' => ['required', Rule::enum(ConversionDirection::class)],
            'total' => ['required', 'integer', 'min:1', 'max:'.self::MAX_FILES],
        ]);

        $direction = ConversionDirection::from($validated['direction']);

        if ($direction === ConversionDirection::ToText && $validated['total'] > 1) {
            return response()->json([
                'message' => 'Envie uma planilha por vez ao converter para .txt.',
            ], 422);
        }

        $conversion = SpedConversion::create([
            'user_id' => $request->user()->id,
            'direction' => $direction,
            'input_count' => $validated['total'],
            'uploaded_count' => 0,
            'input_names' => [],
            'workspace_path' => storage_path('app/private/sped-work/'.Str::ulid()),
            'status' => SpedConversion::STATUS_UPLOADING,
        ]);

        $this->makeDirectory($conversion->workspace_path.'/entrada');

        return response()->json([
            'id' => $conversion->id,
            'uploaded' => 0,
            'total' => $conversion->input_count,
        ], 201);
    }

    /**
     * Recebe um arquivo do lote.
     */
    public function upload(Request $request, SpedConversion $conversion): JsonResponse
    {
        $this->authorizeBatch($request, $conversion);

        $expected = $conversion->direction === ConversionDirection::ToSpreadsheet ? 'txt' : 'xlsx';

        $validated = $request->validate([
            'index' => ['required', 'integer', 'min:0', 'max:'.(self::MAX_FILES - 1)],
            'file' => ['required', 'file', 'max:'.(self::MAX_FILE_MB * 1024)],
        ]);

        $upload = $request->file('file');
        $extension = strtolower((string) $upload->getClientOriginalExtension());

        if ($extension !== $expected) {
            return response()->json([
                'message' => sprintf(
                    'O arquivo %s é .%s; esta conversão aceita apenas .%s.',
                    $upload->getClientOriginalName(),
                    $extension,
                    $expected,
                ),
            ], 422);
        }

        $upload->move(
            $conversion->workspace_path.'/entrada',
            sprintf('%03d.%s', $validated['index'], $expected),
        );

        $names = $conversion->input_names;
        $names[] = $upload->getClientOriginalName();

        $conversion->forceFill([
            'input_names' => $names,
            'uploaded_count' => $conversion->uploaded_count + 1,
        ])->save();

        return response()->json([
            'uploaded' => $conversion->uploaded_count,
            'total' => $conversion->input_count,
        ]);
    }

    /**
     * Fecha o lote e entrega para a fila.
     */
    public function convert(Request $request, SpedConversion $conversion): JsonResponse
    {
        $this->authorizeBatch($request, $conversion);

        if ($conversion->uploaded_count === 0) {
            return response()->json(['message' => 'Nenhum arquivo chegou ao servidor.'], 422);
        }

        $conversion->forceFill([
            'input_count' => $conversion->uploaded_count,
            'processed_count' => 0,
            'row_count' => 0,
            'sheet_count' => 0,
            'status' => SpedConversion::STATUS_PENDING,
        ])->save();

        // A volta (planilha -> .txt) é um arquivo só: continua num job único.
        if ($conversion->direction === ConversionDirection::ToText) {
            ProcessSpedConversion::dispatch($conversion);

            return response()->json(['id' => $conversion->id, 'status' => $conversion->status]);
        }

        // Uma tentativa anterior pode ter deixado planilhas pela metade; elas
        // entrariam no .zip final como se fossem do lote atual.
        $this->deleteDirectory($conversion->workspace_path.'/planilhas');

        $chunks = (new ChunkPlanner)->plan($this->inputFiles($conversion));

        $conversion->forceFill(['chunk_count' => count($chunks)])->save();

        $id = $conversion->id;

        Bus::batch(array_map(
            fn (array $files, int $index) => new ProcessSpedChunk($id, $index, $files),
            $chunks,
            array_keys($chunks),
        ))
            ->name('SPED #'.$id)
            ->allowFailures(false)
            ->then(fn () => FinalizeSpedConversion::dispatch($id))
            ->catch(function (Batch $batch, ?Throwable $e) use ($id) {
                SpedConversion::whereKey($id)->update([
                    'status' => SpedConversion::STATUS_FAILED,
                    'error_message' => Str::limit($e?->getMessage() ?? 'A conversão foi interrompida.', 500),
                    'finished_at' => now(),
                ]);
            })
            ->dispatch();

        return response()->json([
            'id' => $conversion->id,
            'status' => $conversion->status,
            'chunks' => count($chunks),
        ]);
    }

    /**
     * Desiste do lote e limpa o que já subiu.
     */
    public function destroy(Request $request, SpedConversion $conversion): JsonResponse
    {
        $this->authorizeBatch($request, $conversion);

        $this->deleteDirectory((string) $conversion->workspace_path);
        $conversion->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Os arquivos foram gravados com prefixo numérico para preservar a ordem.
     *
     * @return list<string>
     */
    private function inputFiles(SpedConversion $conversion): array
    {
        $files = glob($conversion->workspace_path.'/entrada/*') ?: [];

        usort($files, static fn (string $a, string $b) => (int) basename($a) <=> (int) basename($b));

        return array_values($files);
    }

    private function authorizeBatch(Request $request, SpedConversion $conversion): void
    {
        abort_unless($conversion->user_id === $request->user()->id, 403);
        abort_unless($conversion->status === SpedConversion::STATUS_UPLOADING, 409, 'Este lote já foi fechado.');
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
