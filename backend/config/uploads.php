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
    |
    | "mka" is what the browser sends for a video: it copies out only the
    | audio track (frontend/src/lib/extractAudio.ts) and uploads that.
    |
    */

    'allowed_extensions' => ['mp4', 'mov', 'mkv', 'ts', 'mp3', 'wav', 'm4a', 'mka'],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Nothing is kept once the user has their subtitles: the media, audio and
    | transcript go as soon as the subtitles are written, and the subtitles
    | (and the upload itself) as soon as they are downloaded. Anything never
    | delivered is deleted this many hours after processing ended, and an
    | upload never completed this many hours after it started, by the hourly
    | `model:prune`. Keep in step with RETENTION_HOURS in
    | frontend/src/types/upload.ts.
    |
    */

    'retention_hours' => (int) env('UPLOADS_RETENTION_HOURS', 6),

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
