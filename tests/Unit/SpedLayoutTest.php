<?php

namespace Tests\Unit;

use App\Enums\SpedModel;
use App\Services\Sped\SpedLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpedLayoutTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: SpedModel}>
     */
    public static function openingLines(): array
    {
        return [
            'sped fiscal' => ['|0000|017|0|01012023|31012023|EMPRESA|22137526000150||MG|123|3106200|||B|0|', SpedModel::EfdIcms],
            'efd contribuições' => ['|0000|006|0|||01012023|31012023|EMPRESA|22137526000150|MG|3106200||00|9|', SpedModel::EfdContribuicoes],
            'ecd' => ['|0000|LECD|01012023|31122023|EMPRESA|22137526000150|MG|123|3106200||', SpedModel::Ecd],
            'ecf' => ['|0000|LECF|0010|22137526000150|EMPRESA|4|0|||01012023|31122023|S|X|0|1||', SpedModel::Ecf],
        ];
    }

    #[DataProvider('openingLines')]
    public function test_it_detects_the_model_from_the_opening_record(string $line, SpedModel $expected): void
    {
        $this->assertSame($expected, SpedModel::detectFromOpeningLine($line));
    }

    public function test_it_ignores_lines_that_are_not_the_opening_record(): void
    {
        $this->assertNull(SpedModel::detectFromOpeningLine('|0150|FOR001|FORNECEDOR|1058|'));
        $this->assertNull(SpedModel::detectFromOpeningLine('lixo'));
    }

    public function test_every_model_has_a_loadable_layout(): void
    {
        foreach (SpedModel::cases() as $model) {
            $layout = SpedLayout::forModel($model);

            $this->assertNotEmpty($layout->dataRegisters(), $model->value);
            $this->assertNotEmpty($layout->fields('0000'), $model->value);
            $this->assertNull($layout->parent('0000'), $model->value);
        }
    }

    public function test_it_builds_the_ancestry_chain_of_a_register(): void
    {
        $layout = SpedLayout::forModel(SpedModel::EfdIcms);

        $this->assertSame(['C001', 'C100', 'C170'], $layout->ancestry('C170'));
        $this->assertSame(['C001', 'C100', 'C195', 'C197'], $layout->ancestry('C197'));
        $this->assertSame(['0000'], $layout->ancestry('0000'));
    }

    public function test_registers_are_ordered_by_block_and_then_by_number(): void
    {
        $layout = SpedLayout::forModel(SpedModel::EfdIcms);

        $registers = ['9999', 'C170', '0001', 'C110', '1010', '0000', 'B001'];
        usort($registers, fn (string $a, string $b) => $layout->orderIndex($a) <=> $layout->orderIndex($b));

        $this->assertSame(['0000', '0001', 'B001', 'C110', 'C170', '1010', '9999'], $registers);
    }

    public function test_the_two_efd_models_map_fields_positionally(): void
    {
        foreach ([SpedModel::EfdIcms, SpedModel::EfdContribuicoes] as $model) {
            $layout = SpedLayout::forModel($model);

            $this->assertNull($layout->columnMap('0000', $layout->fieldCount('0000')));
        }
    }

    public function test_ecd_reorders_the_fields_of_registers_that_changed_layout(): void
    {
        $layout = SpedLayout::forModel(SpedModel::Ecd);

        // O J150 do .txt traz 12 campos numa ordem diferente das 18 colunas.
        $map = $layout->columnMap('J150', 12);

        $this->assertNotNull($map);
        $this->assertCount(12, $map);
        $this->assertSame(13, $map[0], 'O primeiro campo do .txt é o NU_ORDEM, 14ª coluna.');
        $this->assertSame(18, $layout->fieldCount('J150'));
    }

    public function test_it_finds_the_columns_that_identify_the_source_file(): void
    {
        foreach (SpedModel::cases() as $model) {
            $indexes = SpedLayout::forModel($model)->openingKeyIndexes();

            $this->assertArrayHasKey('dt_ini', $indexes, $model->value);
            $this->assertArrayHasKey('cnpj', $indexes, $model->value);
        }
    }
}
