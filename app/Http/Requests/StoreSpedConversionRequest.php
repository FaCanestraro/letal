<?php

namespace App\Http\Requests;

use App\Enums\ConversionDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSpedConversionRequest extends FormRequest
{
    /**
     * Teto da aplicação. O PHP tem o seu próprio, em max_file_uploads, e é ele
     * que manda: acima dele os arquivos excedentes somem sem erro nenhum.
     */
    public const MAX_FILES = 360;

    public const MAX_FILE_MB = 128;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::enum(ConversionDirection::class)],
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'max:'.(self::MAX_FILE_MB * 1024)],
            'expected_files' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            $this->guardAgainstSilentTruncation(...),
            $this->guardExtensions(...),
        ];
    }

    /**
     * O navegador informa quantos arquivos enviou. Se chegarem menos, o corte
     * veio do servidor — quase sempre max_file_uploads — e seguir adiante
     * geraria uma planilha faltando períodos sem ninguém perceber.
     */
    private function guardAgainstSilentTruncation(Validator $validator): void
    {
        $expected = $this->integer('expected_files');
        $received = count($this->file('files') ?? []);

        if ($expected <= 0 || $received >= $expected) {
            return;
        }

        $limit = (int) ini_get('max_file_uploads');

        $validator->errors()->add('files', sprintf(
            'O servidor recebeu apenas %d dos %d arquivos enviados. O limite atual do PHP é '.
            'max_file_uploads=%d — ajuste o php.ini e reinicie o servidor, ou envie em lotes menores.',
            $received,
            $expected,
            $limit,
        ));
    }

    private function guardExtensions(Validator $validator): void
    {
        $direction = ConversionDirection::tryFrom((string) $this->string('direction'));

        if ($direction === null) {
            return;
        }

        $expected = $direction === ConversionDirection::ToSpreadsheet ? ['txt'] : ['xlsx'];

        foreach ($this->file('files') ?? [] as $index => $file) {
            $extension = strtolower((string) $file->getClientOriginalExtension());

            if (! in_array($extension, $expected, true)) {
                $validator->errors()->add("files.{$index}", sprintf(
                    'O arquivo %s é .%s; esta conversão aceita apenas .%s.',
                    $file->getClientOriginalName(),
                    $extension,
                    implode(' ou .', $expected),
                ));
            }
        }

        if ($direction === ConversionDirection::ToText && count($this->file('files') ?? []) > 1) {
            $validator->errors()->add('files', 'Envie uma planilha por vez ao converter para .txt.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.required' => 'Selecione ao menos um arquivo.',
            'files.max' => 'Envie no máximo '.self::MAX_FILES.' arquivos por conversão.',
            'files.*.max' => 'Cada arquivo pode ter no máximo '.self::MAX_FILE_MB.' MB.',
        ];
    }
}
