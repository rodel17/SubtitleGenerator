<?php

namespace App\Jobs;

use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Services\ChunkedUploadService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Free disk space: delete the original media of uploads that finished (ready or
 * failed) more than `uploads.media_retention_hours` ago. Scheduled hourly.
 */
class DeleteExpiredMedia implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(ChunkedUploadService $uploads): void
    {
        // Nothing changes a finished upload, so its last update is when it finished
        Upload::query()
            ->whereIn('status', [UploadStatus::Ready, UploadStatus::Failed])
            ->whereNotNull('path')
            ->where('updated_at', '<=', now()->subHours(config('uploads.media_retention_hours')))
            ->lazyById()
            ->each(fn (Upload $upload) => $uploads->deleteMedia($upload));
    }
}
