<?php

namespace App\Models;

use App\Enums\ConversionDirection;
use App\Enums\SpedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'direction', 'model', 'input_count', 'uploaded_count', 'processed_count',
    'chunk_count', 'input_names', 'output_path',
    'output_name', 'output_size', 'row_count', 'sheet_count', 'status', 'error_message',
    'workspace_path', 'started_at', 'finished_at',
])]
class SpedConversion extends Model
{
    /** Lote aberto: os arquivos ainda estão subindo, um por requisição. */
    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => ConversionDirection::class,
            'model' => SpedModel::class,
            'input_names' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<SpedConversion>  $query
     */
    public function scopeSucceeded(Builder $query): void
    {
        $query->where('status', self::STATUS_DONE);
    }

    /**
     * Quanto do lote já foi convertido, de 0 a 100.
     */
    public function progress(): int
    {
        if ($this->input_count <= 0) {
            return 0;
        }

        return (int) round(min($this->processed_count, $this->input_count) / $this->input_count * 100);
    }

    /**
     * Quanto tempo falta, estimado pelo ritmo observado até agora.
     * Devolve null enquanto não há amostra suficiente para arriscar um número.
     */
    public function etaInSeconds(): ?int
    {
        if ($this->status !== self::STATUS_PROCESSING
            || $this->started_at === null
            || $this->processed_count < 1
            || $this->processed_count >= $this->input_count) {
            return null;
        }

        $elapsed = max(1, (int) $this->started_at->diffInSeconds(now(), absolute: true));
        $perFile = $elapsed / $this->processed_count;

        return (int) round(($this->input_count - $this->processed_count) * $perFile);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_DONE && $this->output_path !== null;
    }

    /**
     * Ainda na fila ou em execução — a tela consulta o status enquanto for true.
     */
    public function isRunning(): bool
    {
        return in_array(
            $this->status,
            [self::STATUS_UPLOADING, self::STATUS_PENDING, self::STATUS_PROCESSING],
            strict: true,
        );
    }

    /**
     * Duração da conversão em segundos, quando já terminou.
     */
    public function durationInSeconds(): ?float
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return round((float) $this->finished_at->diffInMilliseconds($this->started_at, absolute: true) / 1000, 1);
    }

    /**
     * @param  Builder<SpedConversion>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_UPLOADING, self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }
}
