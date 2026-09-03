<?php

namespace App\Http\Controllers;

use App\Enums\ConversionDirection;
use App\Enums\SpedModel;
use App\Http\Requests\StoreSpedConversionRequest;
use App\Jobs\ProcessSpedConversion;
use App\Models\SpedConversion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SpedController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Sped/Index', [
            'conversions' => Inertia::always(fn () => $this->conversions($request)),
            'models' => array_map(
                fn (SpedModel $m) => ['value' => $m->value, 'label' => $m->label(), 'short' => $m->shortLabel()],
                SpedModel::cases(),
            ),
            'uploadLimit' => [
                'files' => min(StoreSpedConversionRequest::MAX_FILES, (int) ini_get('max_file_uploads')),
                'appMax' => StoreSpedConversionRequest::MAX_FILES,
                'phpMax' => (int) ini_get('max_file_uploads'),
                'perFileMb' => StoreSpedConversionRequest::MAX_FILE_MB,
                'postMax' => ini_get('post_max_size'),
            ],
        ]);
    }

    /**
     * Recebe os arquivos, registra a conversão como pendente e entrega o
     * trabalho para a fila. A tela acompanha o andamento pelo status.
     */
    public function store(StoreSpedConversionRequest $request): RedirectResponse
    {
        $direction = ConversionDirection::from($request->string('direction')->toString());
        $uploads = $request->file('files');

        $workspace = storage_path('app/private/sped-work/'.Str::ulid());
        $this->makeDirectory($workspace.'/entrada');

        foreach ($uploads as $index => $upload) {
            $name = sprintf(
                '%03d-%s.%s',
                $index,
                Str::slug(pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'arquivo',
                strtolower($upload->getClientOriginalExtension()),
            );

            $upload->move($workspace.'/entrada', $name);
        }

        $conversion = SpedConversion::create([
            'user_id' => $request->user()->id,
            'direction' => $direction,
            'input_count' => count($uploads),
            'input_names' => array_map(fn ($f) => $f->getClientOriginalName(), $uploads),
            'workspace_path' => $workspace,
            'status' => SpedConversion::STATUS_PENDING,
        ]);

        ProcessSpedConversion::dispatch($conversion);

        return back()->with(
            'success',
            'Conversão enviada para processamento. O resultado aparece na lista assim que ficar pronto.'
        );
    }

    /**
     * Entrega o resultado da conversão.
     *
     * A rota é assinada em vez de depender da sessão: um download de vários GB
     * dura mais que a sessão e o gerenciador do sistema operacional refaz a
     * requisição por conta própria — sem assinatura, a tentativa cai no login e
     * o usuário recebe um login.html no lugar do arquivo.
     *
     * A resposta é um BinaryFileResponse, e não um fluxo, porque ele anuncia
     * Accept-Ranges: uma queda de conexão retoma de onde parou em vez de
     * recomeçar os 7 GB.
     */
    public function download(SpedConversion $conversion): BinaryFileResponse
    {
        abort_unless($conversion->isDownloadable(), 404);

        $path = Storage::disk('local')->path($conversion->output_path);

        abort_unless(is_file($path), 404);

        return response()->download($path, (string) $conversion->output_name);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function conversions(Request $request): array
    {
        return SpedConversion::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (SpedConversion $c) => [
                'id' => $c->id,
                'direction' => $c->direction->value,
                'direction_label' => $c->direction->label(),
                'model' => $c->model?->shortLabel(),
                'input_count' => $c->input_count,
                'processed_count' => $c->processed_count,
                'chunk_count' => $c->chunk_count,
                'progress' => $c->progress(),
                'eta' => $c->etaInSeconds(),
                'input_names' => $c->input_names,
                'output_name' => $c->output_name,
                'output_size' => $c->output_size,
                'row_count' => $c->row_count,
                'sheet_count' => $c->sheet_count,
                'status' => $c->status,
                'error_message' => $c->error_message,
                'downloadable' => $c->isDownloadable(),
                'download_url' => $c->isDownloadable()
                    ? URL::temporarySignedRoute('sped.download', now()->addHours(12), ['conversion' => $c->id])
                    : null,
                'running' => $c->isRunning(),
                'duration' => $c->durationInSeconds(),
                'created_at' => $c->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException("Não foi possível criar {$path}.");
        }
    }
}
