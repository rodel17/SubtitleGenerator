<?php

namespace App\Models;

use App\Enums\UploadStatus;
use App\Services\ChunkedUploadService;
use Database\Factories\UploadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class Upload extends Model
{
    /** @use HasFactory<UploadFactory> */
    use HasFactory, HasUuids, Prunable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'original_name',
        'extension',
        'mime_type',
        'size',
        'chunk_size',
        'total_chunks',
        'language',
        'subtitle_format',
        'status',
        'path',
        'completed_at',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'uploading',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'status' => UploadStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Expected byte length of the chunk at the given zero-based index.
     */
    public function expectedChunkSize(int $index): int
    {
        if ($index === $this->total_chunks - 1) {
            return $this->size - ($this->chunk_size * $index);
        }

        return $this->chunk_size;
    }

    public function isUploading(): bool
    {
        return $this->status === UploadStatus::Uploading;
    }

    /**
     * Incomplete uploads that were abandoned long enough ago to discard.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('status', UploadStatus::Uploading)
            ->where('created_at', '<=', now()->subHours(config('uploads.abandoned_after_hours')));
    }

    /**
     * Remove the upload's files before the record is pruned.
     */
    protected function pruning(): void
    {
        app(ChunkedUploadService::class)->deleteFiles($this);
    }
}
