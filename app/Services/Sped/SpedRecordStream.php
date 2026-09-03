<?php

namespace App\Services\Sped;

use App\Enums\SpedModel;
use Generator;
use RuntimeException;

/**
 * Lê um arquivo .txt do SPED linha a linha.
 *
 * Os arquivos chegam em ISO-8859-1 com quebra CRLF e trazem a assinatura
 * digital anexada ao final — tudo que não for uma linha de registro válida
 * é descartado na leitura.
 */
final class SpedRecordStream
{
    private const REGISTER = '/^\|([0-9A-Z][0-9]{3})\|/';

    public function __construct(private readonly string $path) {}

    public function detectModel(): SpedModel
    {
        $handle = $this->open();

        try {
            while (($line = fgets($handle)) !== false) {
                $line = $this->decode($line);

                if (preg_match(self::REGISTER, $line) !== 1) {
                    continue;
                }

                $model = SpedModel::detectFromOpeningLine($line);

                if ($model !== null) {
                    return $model;
                }

                break;
            }
        } finally {
            fclose($handle);
        }

        throw new RuntimeException(
            'Não foi possível identificar o modelo do arquivo '.basename($this->path).
            '. Confira se ele começa com o registro 0000.'
        );
    }

    /**
     * @return Generator<int, array{0: string, 1: list<string>}>
     */
    public function records(): Generator
    {
        $handle = $this->open();

        try {
            while (($line = fgets($handle)) !== false) {
                $line = $this->decode($line);

                if (preg_match(self::REGISTER, $line, $matches) !== 1) {
                    continue;
                }

                $fields = explode('|', $line);

                // O primeiro e o último elemento são as bordas vazias do "|...|".
                yield [$matches[1], array_slice($fields, 2, count($fields) - 3)];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function open()
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Não foi possível abrir {$this->path}.");
        }

        return $handle;
    }

    private function decode(string $line): string
    {
        return mb_convert_encoding(rtrim($line, "\r\n"), 'UTF-8', 'ISO-8859-1');
    }
}
