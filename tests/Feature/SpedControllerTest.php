<?php

namespace Tests\Feature;

use App\Enums\ConversionDirection;
use App\Enums\SpedModel;
use App\Models\SpedConversion;
use App\Models\User;
use App\Services\Sped\SpedLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SpedControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_reach_the_converter(): void
    {
        $this->get(route('sped.index'))->assertRedirect(route('login'));
        $this->post(route('sped.store'))->assertRedirect(route('login'));
    }

    public function test_the_converter_page_lists_the_supported_models(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('sped.index'))
            ->assertOk();
    }

    public function test_it_converts_uploaded_files_and_records_the_conversion(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('sped.store'), [
            'direction' => ConversionDirection::ToSpreadsheet->value,
            'files' => [$this->spedUpload('01012023', '31012023'), $this->spedUpload('01022023', '28022023')],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();

        $conversion = SpedConversion::sole();

        $this->assertSame($user->id, $conversion->user_id);
        $this->assertSame(SpedModel::EfdIcms, $conversion->model);
        $this->assertSame(2, $conversion->input_count);
        $this->assertSame(SpedConversion::STATUS_DONE, $conversion->status);
        $this->assertGreaterThan(0, $conversion->sheet_count);
        $this->assertTrue($conversion->isDownloadable());

        Storage::disk('local')->assertExists($conversion->output_path);
    }

    public function test_the_owner_can_download_the_result(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('sped.store'), [
            'direction' => ConversionDirection::ToSpreadsheet->value,
            'files' => [$this->spedUpload('01012023', '31012023')],
        ]);

        $conversion = SpedConversion::sole();

        $url = URL::temporarySignedRoute('sped.download', now()->addHour(), ['conversion' => $conversion->id]);

        $this->actingAs($user)
            ->get($url)
            ->assertOk()
            ->assertDownload($conversion->output_name);

        // A assinatura é a credencial: o download tem de funcionar sem sessão,
        // porque o gerenciador do sistema refaz a requisição por conta própria.
        $this->get($url)->assertOk();
    }

    public function test_an_unsigned_download_link_is_rejected(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('sped.store'), [
            'direction' => ConversionDirection::ToSpreadsheet->value,
            'files' => [$this->spedUpload('01012023', '31012023')],
        ]);

        // Sem assinatura válida, ninguém baixa — nem o dono.
        $this->actingAs(User::factory()->create())
            ->get(route('sped.download', SpedConversion::sole()))
            ->assertForbidden();
    }

    public function test_it_rejects_a_file_with_the_wrong_extension(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sped.store'), [
                'direction' => ConversionDirection::ToSpreadsheet->value,
                'files' => [UploadedFile::fake()->create('planilha.xlsx', 10)],
            ])
            ->assertSessionHasErrors('files.0');

        $this->assertSame(0, SpedConversion::count());
    }

    public function test_it_accepts_only_one_spreadsheet_when_converting_back(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sped.store'), [
                'direction' => ConversionDirection::ToText->value,
                'files' => [
                    UploadedFile::fake()->create('a.xlsx', 10),
                    UploadedFile::fake()->create('b.xlsx', 10),
                ],
            ])
            ->assertSessionHasErrors('files');
    }

    public function test_a_failed_conversion_is_recorded_with_the_reason(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $broken = UploadedFile::fake()->createWithContent('quebrado.txt', "linha sem registro\r\n");

        $this->actingAs($user)
            ->post(route('sped.store'), [
                'direction' => ConversionDirection::ToSpreadsheet->value,
                'files' => [$broken],
            ])
            ->assertRedirect();

        $conversion = SpedConversion::sole();

        $this->assertSame(SpedConversion::STATUS_FAILED, $conversion->status);
        $this->assertStringContainsString('identificar o modelo', (string) $conversion->error_message);
        $this->assertFalse($conversion->isDownloadable());
    }

    public function test_the_full_cycle_returns_a_zip_of_text_files(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('sped.store'), [
            'direction' => ConversionDirection::ToSpreadsheet->value,
            'files' => [$this->spedUpload('01012023', '31012023')],
        ]);

        $spreadsheet = SpedConversion::sole();
        $content = Storage::disk('local')->get($spreadsheet->output_path);

        $this->actingAs($user)->post(route('sped.store'), [
            'direction' => ConversionDirection::ToText->value,
            'files' => [UploadedFile::fake()->createWithContent('planilha.xlsx', $content)],
        ])->assertSessionHasNoErrors();

        $back = SpedConversion::where('direction', ConversionDirection::ToText)->sole();

        $this->assertSame(SpedConversion::STATUS_DONE, $back->status, (string) $back->error_message);
        $this->assertSame(SpedModel::EfdIcms, $back->model);
        $this->assertStringEndsWith('.zip', (string) $back->output_name);
    }

    public function test_it_refuses_a_batch_the_server_truncated(): void
    {
        // O navegador diz que mandou 42; se só 20 chegam, o corte foi do PHP.
        $this->actingAs(User::factory()->create())
            ->post(route('sped.store'), [
                'direction' => ConversionDirection::ToSpreadsheet->value,
                'expected_files' => 42,
                'files' => [$this->spedUpload('01012023', '31012023')],
            ])
            ->assertSessionHasErrors('files');

        $this->assertSame(0, SpedConversion::count());
    }

    public function test_it_accepts_a_batch_whose_count_matches(): void
    {
        Storage::fake('local');

        $this->actingAs(User::factory()->create())
            ->post(route('sped.store'), [
                'direction' => ConversionDirection::ToSpreadsheet->value,
                'expected_files' => 2,
                'files' => [$this->spedUpload('01012023', '31012023'), $this->spedUpload('01022023', '28022023')],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SpedConversion::count());
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
}
