<?php

namespace Tests\Feature;

use App\Enums\ConversionDirection;
use App\Enums\SpedModel;
use App\Jobs\ProcessSpedConversion;
use App\Models\SpedConversion;
use App\Models\User;
use App\Services\Sped\ConversionRunner;
use App\Services\Sped\SpedLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class SpedConversionQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_upload_only_queues_the_work(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('sped.store'), [
                'direction' => ConversionDirection::ToSpreadsheet->value,
                'files' => [$this->upload()],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $conversion = SpedConversion::sole();

        $this->assertSame(SpedConversion::STATUS_PENDING, $conversion->status);
        $this->assertTrue($conversion->isRunning());
        $this->assertFalse($conversion->isDownloadable());
        $this->assertNull($conversion->model);
        $this->assertDirectoryExists((string) $conversion->workspace_path);

        Queue::assertPushed(
            ProcessSpedConversion::class,
            fn (ProcessSpedConversion $job) => $job->conversion->is($conversion),
        );
    }

    public function test_running_the_job_completes_the_conversion_and_clears_the_workspace(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('sped.store'), [
            'direction' => ConversionDirection::ToSpreadsheet->value,
            'files' => [$this->upload()],
        ]);

        $conversion = SpedConversion::sole();
        $workspace = (string) $conversion->workspace_path;

        app(ConversionRunner::class)->run($conversion);
        $conversion->refresh();

        $this->assertSame(SpedConversion::STATUS_DONE, $conversion->status, (string) $conversion->error_message);
        $this->assertSame(SpedModel::EfdIcms, $conversion->model);
        $this->assertFalse($conversion->isRunning());
        $this->assertNotNull($conversion->started_at);
        $this->assertNotNull($conversion->finished_at);
        $this->assertIsFloat($conversion->durationInSeconds());

        $this->assertNull($conversion->workspace_path);
        $this->assertDirectoryDoesNotExist($workspace, 'Os arquivos enviados não devem sobrar no disco.');
    }

    public function test_a_conversion_whose_files_vanished_fails_with_a_clear_reason(): void
    {
        $conversion = SpedConversion::create([
            'user_id' => User::factory()->create()->id,
            'direction' => ConversionDirection::ToSpreadsheet,
            'input_count' => 1,
            'input_names' => ['sumiu.txt'],
            'workspace_path' => storage_path('app/private/sped-work/inexistente'),
            'status' => SpedConversion::STATUS_PENDING,
        ]);

        app(ConversionRunner::class)->run($conversion);

        $this->assertSame(SpedConversion::STATUS_FAILED, $conversion->refresh()->status);
        $this->assertStringContainsString('não estão mais disponíveis', (string) $conversion->error_message);
    }

    public function test_a_job_that_dies_marks_the_conversion_as_failed(): void
    {
        $conversion = SpedConversion::create([
            'user_id' => User::factory()->create()->id,
            'direction' => ConversionDirection::ToSpreadsheet,
            'input_count' => 1,
            'input_names' => ['grande.txt'],
            'status' => SpedConversion::STATUS_PROCESSING,
        ]);

        (new ProcessSpedConversion($conversion))->failed(new RuntimeException('Tempo esgotado.'));

        $conversion->refresh();

        $this->assertSame(SpedConversion::STATUS_FAILED, $conversion->status);
        $this->assertSame('Tempo esgotado.', $conversion->error_message);
        $this->assertNotNull($conversion->finished_at);
    }

    public function test_the_job_retries_once_and_allows_a_long_conversion(): void
    {
        $job = new ProcessSpedConversion(new SpedConversion);

        $this->assertSame(1, $job->tries, 'Reprocessar um arquivo inválido daria o mesmo erro.');
        $this->assertGreaterThanOrEqual(600, $job->timeout, 'A ECD do acervo leva cerca de meio minuto.');
    }

    public function test_the_page_reports_which_conversions_are_still_running(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('sped.store'), [
            'direction' => ConversionDirection::ToSpreadsheet->value,
            'files' => [$this->upload()],
        ]);

        $this->actingAs($user)
            ->get(route('sped.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('conversions', 1)
                ->where('conversions.0.running', true)
                ->where('conversions.0.status', SpedConversion::STATUS_PENDING)
            );
    }

    private function upload(): UploadedFile
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
                'COD_VER' => '017', 'COD_FIN' => '0', 'DT_INI' => '01012023', 'DT_FIN' => '31012023',
                'NOME' => 'EMPRESA TESTE LTDA', 'CNPJ' => '22137526000150', 'UF' => 'MG',
            ]),
            $line('0001', ['IND_MOV' => '0']),
            $line('0150', ['COD_PART' => 'FOR001', 'NOME' => 'FORNECEDOR', 'COD_PAIS' => '1058']),
        ])."\r\n";

        return UploadedFile::fake()->createWithContent(
            'sped.txt',
            mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8'),
        );
    }
}
