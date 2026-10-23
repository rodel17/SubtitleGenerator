<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk that receives chunks and assembled media files.
    | Chunks are appended into a single file, so this must be a local disk.
    |
    */

    'disk' => env('UPLOADS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Size Limits
    |--------------------------------------------------------------------------
    |
    | "max_size" is the largest media file accepted, in bytes (default 500 MB).
    | Keep it in step with MAX_FILE_SIZE in frontend/src/types/upload.ts.
    | "chunk_size" is the size of each uploaded piece, in bytes (default 5 MB).
    | Keep chunk_size below PHP's upload_max_filesize and nginx's
    | client_max_body_size (see Docker/php/uploads.ini, Docker/nginx).
    |
    */

    'max_size' => (int) env('UPLOADS_MAX_SIZE', 500 * 1024 * 1024),

    'chunk_size' => (int) env('UPLOADS_CHUNK_SIZE', 5 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Accepted Media
    |--------------------------------------------------------------------------
    */

    'allowed_extensions' => ['mp4', 'mov', 'mkv', 'ts', 'mp3', 'wav', 'm4a'],

    /*
    |--------------------------------------------------------------------------
    | Abandoned Uploads
    |--------------------------------------------------------------------------
    |
    | Uploads still incomplete after this many hours are pruned, together
    | with their chunks, by the scheduled `model:prune` command.
    |
    */

    'abandoned_after_hours' => (int) env('UPLOADS_ABANDONED_AFTER_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Media Retention
    |--------------------------------------------------------------------------
    |
    | The original media file is deleted this many hours after the upload is
    | ready or has failed, by the hourly `DeleteExpiredMedia` job; only the
    | transcript and subtitles are kept. The grace period leaves time to
    | retry a failed transcription.
    |
    */

    'media_retention_hours' => (int) env('UPLOADS_MEDIA_RETENTION_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Rate Limit
    |--------------------------------------------------------------------------
    |
    | How many uploads each user may start per hour. Every upload queues a
    | CPU-heavy Whisper transcription, so this keeps one user from filling
    | the queue.
    |
    */

    'max_per_hour' => (int) env('UPLOADS_MAX_PER_HOUR', 30),

];
