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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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
        'last_modified',
        'size',
        'chunk_size',
        'total_chunks',
        'language',
        'subtitle_format',
        'status',
        'error',
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
            'last_modified' => 'integer',
            'size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'status' => UploadStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    /**
     * The user who uploaded the media; only they can see or change it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * Move to a new status. Any progress reported for the previous step is cleared.
     */
    public function transitionTo(UploadStatus $status): void
    {
        $this->update(['status' => $status, 'error' => null]);
        Cache::forget($this->progressCacheKey());
    }

    /**
     * Stop processing and record a reason the user can read.
     */
    public function markFailed(string $error): void
    {
        $this->update(['status' => UploadStatus::Failed, 'error' => Str::limit($error, 497)]);
        Cache::forget($this->progressCacheKey());
    }

    /**
     * Progress of the current background step, 0–100. Kept in the cache (Redis)
     * rather than the database because jobs update it many times per second.
     */
    public function reportProgress(int $percent): void
    {
        Cache::put($this->progressCacheKey(), max(0, min(100, $percent)), now()->addDay());
    }

    public function progress(): ?int
    {
        return $this->status->isProcessing() ? (int) Cache::get($this->progressCacheKey(), 0) : null;
    }

    /**
     * Subtitle formats to generate: "both" means SRT and VTT.
     *
     * @return list<string>
     */
    public function subtitleFormats(): array
    {
        return $this->subtitle_format === 'both' ? ['srt', 'vtt'] : [$this->subtitle_format];
    }

    public function audioDirectory(): string
    {
        return "audio/{$this->id}";
    }

    public function transcriptPath(): string
    {
        return "transcripts/{$this->id}.json";
    }

    public function subtitlePath(string $format): string
    {
        return "subtitles/{$this->id}.{$format}";
    }

    private function progressCacheKey(): string
    {
        return "uploads:{$this->id}:progress";
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
