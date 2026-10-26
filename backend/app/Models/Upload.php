<?php

namespace App\Models;

use App\Enums\EmailStatus;
use App\Enums\UploadStatus;
use App\Services\ChunkedUploadService;
use Database\Factories\UploadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
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
        'translate_to',
        'translation_engine',
        'subtitle_format',
        'email_when_ready',
        'status',
        'error',
        'email_status',
        'email_error',
        'path',
        'completed_at',
        'finished_at',
    ];

    /**
     * Uploads still not ready or failed after this long are assumed stuck (a worker
     * died without marking them failed) and deleted with everything else.
     */
    private const STUCK_AFTER_HOURS = 24;

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
            'email_when_ready' => 'boolean',
            'status' => UploadStatus::class,
            'email_status' => EmailStatus::class,
            'completed_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * The subtitle files are written; start the clock for deleting them if nobody downloads them.
     */
    public function markReady(): void
    {
        $this->update(['finished_at' => now()]);
        $this->transitionTo(UploadStatus::Ready);
    }

    /**
     * Stop processing and record a reason the user can read. Nothing is kept
     * for a failed upload: trying again means uploading the file again.
     */
    public function markFailed(string $error): void
    {
        app(ChunkedUploadService::class)->deleteFiles($this);

        $this->update([
            'status' => UploadStatus::Failed,
            'error' => Str::limit($error, 497),
            'path' => null,
            'finished_at' => now(),
        ]);
        Cache::forget($this->progressCacheKey());
    }

    /**
     * When the upload is deleted if it is never downloaded; null while still processing.
     */
    public function expiresAt(): ?Carbon
    {
        return $this->finished_at?->copy()->addHours(config('uploads.retention_hours'));
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

    /**
     * The translated transcript (TranslateTranscript); only exists when translating.
     */
    public function translationPath(): string
    {
        return "translations/{$this->id}.json";
    }

    public function subtitlePath(string $format): string
    {
        return "subtitles/{$this->id}.{$format}";
    }

    /**
     * "Team Interview.mp4" -> "Team Interview.srt", or "Team Interview.fil.srt" when
     * translated into Filipino: what the user gets, by download or email.
     */
    public function subtitleFileName(string $format): string
    {
        $language = $this->translate_to !== null ? ".{$this->translate_to}" : '';

        return pathinfo($this->original_name, PATHINFO_FILENAME)."{$language}.{$format}";
    }

    private function progressCacheKey(): string
    {
        return "uploads:{$this->id}:progress";
    }

    /**
     * Uploads to delete: finished (ready or failed) but never downloaded within
     * the retention window, never completed within it, or stuck in processing.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subHours(config('uploads.retention_hours'));

        return static::query()
            ->where('finished_at', '<=', $cutoff)
            ->orWhere(fn (Builder $query) => $query
                ->where('status', UploadStatus::Uploading)
                ->where('created_at', '<=', $cutoff))
            ->orWhere(fn (Builder $query) => $query
                ->whereNull('finished_at')
                ->where('created_at', '<=', now()->subHours(self::STUCK_AFTER_HOURS)));
    }

    /**
     * Remove the upload's files before the record is pruned.
     */
    protected function pruning(): void
    {
        app(ChunkedUploadService::class)->deleteFiles($this);
    }
}
