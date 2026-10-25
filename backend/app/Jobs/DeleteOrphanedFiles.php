<?php

namespace App\Jobs;

use App\Services\ChunkedUploadService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delete stored files whose upload no longer exists. A job that was still running
 * when its upload was deleted can write a file afterwards; this makes sure such
 * leftovers don't stay on disk. Scheduled hourly, next to `model:prune`.
 */
class DeleteOrphanedFiles implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(ChunkedUploadService $uploads): void
    {
        $uploads->deleteOrphanedFiles();
    }
}
