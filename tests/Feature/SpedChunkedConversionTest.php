<?php

namespace Tests\Feature;

use App\Enums\ConversionDirection;
use App\Enums\SpedModel;
use App\Jobs\FinalizeSpedConversion;
use App\Jobs\ProcessSpedChunk;
use App\Models\SpedConversion;
use App\Models\User;
use App\Services\Sped\ChunkPlanner;
use App\Services\Sped\SpedConverter;
use App\Services\Sped\SpedLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SpedChunkedConversionTest extends TestCase
{
    use RefreshDatabase;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/sped-chunk-'.Str::random(8);
        mkdir($this->workDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $item) {
            @unlink($item);
        }
        @rmdir($this->workDir);

        parent::tearDown();
    }

    public function test_the_planner_keeps_small_batches_in_a_single_group(): void
    {
        $files = [$this->writeFile('a', 10), $this->writeFile('b', 10), $this->writeFile('c', 10)];

        $this->assertCount(1, (new ChunkPlanner)->plan($files));
    }

    public function test_the_planner_breaks_the_batch_when_the_budget_runs_out(): void
    {
        $files = [$this->writeFile('a', 40), $this->writeFile('b', 40), $this->writeFile('c', 40)];

        // Orçamento de 100 linhas: dois arquivos por grupo.
        $groups = (new ChunkPlanner)->plan($files, rowBudget: 100);

        $this->assertCount(2, $groups);
        $this->assertCount(2, $groups[0]);
        $this->assertCount(1, $groups[1]);
    }

    public function test_the_planner_never_drops_a_file_bigger_than_the_budget(): void
    {
        $files = [$this->writeFile('grande', 500)];

        $groups = (new ChunkPlanner)->plan($files, rowBudget: 10);

        $this->assertSame([[$files[0]]], $groups, 'Um arquivo sozinho vai inteiro, nem que estoure.');
    }

    public function test_converting_dispatches_one_job_per_chunk_and_a_finalizer(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $conversion = $this->uploadBatch($user, 2);

        $this->actingAs($user)
            ->postJson(route('sped.batch.convert', $conversion))
            ->assertOk()
            ->assertJsonPath('status', SpedConversion::STATUS_PENDING);

        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 1
                && $batch->jobs->first() instanceof ProcessSpedChunk;
        });

        $conversion->refresh();

        $this->assertSame(2, $conversion->input_count);
        $this->assertSame(1, $conversion->chunk_count);
        $this->assertSame(0, $conversion->processed_count);
    }

    public function test_a_chunk_records_its_progress(): void
    {
        $user = User::factory()->create();
        $conversion = $this->uploadBatch($user, 2);

        $conversion->forceFill(['status' => SpedConversion::STATUS_PENDING])->save();

        $files = glob($conversion->workspace_path.'/entrada/*');
        sort($files);

        (new ProcessSpedChunk($conversion->id, 0, $files))->handle(app(SpedConverter::class));

        $conversion->refresh();

        $this->assertSame(2, $conversion->processed_count);
        $this->assertSame(100, $conversion->progress());
        $this->assertSame(SpedModel::EfdIcms, $conversion->model);
        $this->assertGreaterThan(0, $conversion->row_count);
        $this->assertNotEmpty(glob($conversion->workspace_path.'/planilhas/*.xlsx'));
    }

    public function test_the_finalizer_publishes_the_result_and_clears_the_workspace(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $conversion = $this->uploadBatch($user, 2);
        $workspace = (string) $conversion->workspace_path;

        $files = glob($workspace.'/entrada/*');
        sort($files);

        (new ProcessSpedChunk($conversion->id, 0, $files))->handle(app(SpedConverter::class));
        (new FinalizeSpedConversion($conversion->id))->handle();

        $conversion->refresh();

        $this->assertSame(SpedConversion::STATUS_DONE, $conversion->status);
        $this->assertStringEndsWith('.xlsx', (string) $conversion->output_name);
        $this->assertTrue($conversion->isDownloadable());
        Storage::disk('local')->assertExists($conversion->output_path);

        $this->assertNull($conversion->workspace_path);
        $this->assertDirectoryDoesNotExist($workspace);
    }

    public function test_the_finalizer_zips_when_the_batch_produced_several_workbooks(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $conversion = $this->uploadBatch($user, 2);

        $files = glob($conversion->workspace_path.'/entrada/*');
        sort($files);

        // Dois pedaços de um arquivo cada: duas planilhas.
        (new ProcessSpedChunk($conversion->id, 0, [$files[0]]))->handle(app(SpedConverter::class));
        (new ProcessSpedChunk($conversion->id, 1, [$files[1]]))->handle(app(SpedConverter::class));
        (new FinalizeSpedConversion($conversion->id))->handle();

        $conversion->refresh();

        $this->assertSame(SpedConversion::STATUS_DONE, $conversion->status);
        $this->assertStringEndsWith('.zip', (string) $conversion->output_name);
    }

    public function test_the_finalizer_refuses_to_publish_an_incomplete_batch(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $conversion = $this->uploadBatch($user, 2);

        $files = glob($conversion->workspace_path.'/entrada/*');
        sort($files);

        // Só um dos dois arquivos foi convertido: o outro pedaço morreu.
        (new ProcessSpedChunk($conversion->id, 0, [$files[0]]))->handle(app(SpedConverter::class));

        try {
            (new FinalizeSpedConversion($conversion->id))->handle();
            $this->fail('Publicar um lote pela metade deveria falhar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('parou em 1 de 2 arquivos', $e->getMessage());
        }

        $conversion->refresh();

        $this->assertSame(SpedConversion::STATUS_FAILED, $conversion->status);
        $this->assertFalse($conversion->isDownloadable(), 'Nada pode ficar disponível para download.');
        $this->assertNull($conversion->output_path);
    }

    public function test_it_estimates_the_time_left_from_the_observed_pace(): void
    {
        $conversion = new SpedConversion([
            'input_count' => 100,
            'processed_count' => 25,
        ]);
        $conversion->status = SpedConversion::STATUS_PROCESSING;
        $conversion->started_at = now()->subMinutes(5);

        // 25 arquivos em 5 minutos -> 75 restantes levam cerca de 15.
        $this->assertEqualsWithDelta(15 * 60, $conversion->etaInSeconds(), 30);
        $this->assertSame(25, $conversion->progress());
    }

    public function test_there_is_no_estimate_before_the_first_file_lands(): void
    {
        $conversion = new SpedConversion(['input_count' => 100, 'processed_count' => 0]);
        $conversion->status = SpedConversion::STATUS_PROCESSING;
        $conversion->started_at = now()->subMinute();

        $this->assertNull($conversion->etaInSeconds(), 'Sem amostra, não se arrisca um número.');
    }

    private function uploadBatch(User $user, int $count): SpedConversion
    {
        $response = $this->actingAs($user)->postJson(route('sped.batch.store'), [
            'direction' => ConversionDirection::ToSpreadsheet->value,
            'total' => $count,
        ])->assertCreated();

        $conversion = SpedConversion::findOrFail($response->json('id'));

        foreach (range(0, $count - 1) as $index) {
            $this->actingAs($user)->post(route('sped.batch.upload', $conversion), [
                'index' => $index,
                'file' => $this->spedUpload(sprintf('%02d012023', $index + 1), sprintf('%02d012023', $index + 1)),
            ])->assertOk();
        }

        return $conversion->refresh();
    }

    private function spedUpload(string $start, string $end): UploadedFile
    {
        $layout = SpedLayout::forModel(SpedModel::EfdIcms);

        $line = function (string $register, array $values) use ($layout): string {
            $fields = array_fill(0, $layout->fieldCount($register), '');

            foreach ($layout->fieldNames($register) as $index => $name) {
                if (array_key_exists($name, $values)) {
                    $fields[$index] = $values[$name];
                }
            }

            return '|'.$register.'|'.implode('|', $fields).'|';
        };

        $content = implode("\r\n", [
            $line('0000', [
                'COD_VER' => '017', 'COD_FIN' => '0', 'DT_INI' => $start, 'DT_FIN' => $end,
                'NOME' => 'EMPRESA TESTE LTDA', 'CNPJ' => '22137526000150', 'UF' => 'MG',
            ]),
            $line('0001', ['IND_MOV' => '0']),
            $line('0150', ['COD_PART' => 'FOR001', 'NOME' => 'FORNECEDOR', 'COD_PAIS' => '1058']),
        ])."\r\n";

        return UploadedFile::fake()->createWithContent(
            "sped-{$start}.txt",
            mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8'),
        );
    }

    private function writeFile(string $name, int $lines): string
    {
        $path = $this->workDir.'/'.$name.'.txt';
        file_put_contents($path, str_repeat("|0150|X|NOME|1058|\r\n", $lines));

        return $path;
    }
}
