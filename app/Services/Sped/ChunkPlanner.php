<?php

namespace App\Services\Sped;

use RuntimeException;

/**
 * Divide os arquivos de um lote em grupos que caibam numa planilha.
 *
 * A estimativa usa a contagem de linhas do .txt, que é barata e conservadora:
 * a maior aba nunca tem mais linhas que o arquivo inteiro. Errar para menos não
 * quebra nada — o escritor ainda parte a aba se o grupo estourar o teto.
 */
final class ChunkPlanner
{
    /**
     * @param  list<string>  $files
     * @return list<list<string>>
     */
    public function plan(array $files, ?int $rowBudget = null): array
    {
        $budget = $rowBudget ?? SpedWorkbookWriter::MAX_ROWS_PER_SHEET;

        $groups = [];
        $current = [];
        $running = 0;

        foreach ($files as $file) {
            $lines = $this->countLines($file);

            if ($current !== [] && $running + $lines > $budget) {
                $groups[] = $current;
                $current = [];
                $running = 0;
            }

            $current[] = $file;
            $running += $lines;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups === [] ? [] : $groups;
    }

    private function countLines(string $path): int
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Não foi possível ler {$path}.");
        }

        $lines = 0;

        try {
            while (! feof($handle)) {
                $lines += substr_count((string) fread($handle, 1 << 20), "\n");
            }
        } finally {
            fclose($handle);
        }

        return $lines;
    }
}
