<?php

namespace App\Http\Controllers;

use App\Enums\UploadStatus;
use App\Models\Upload;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubtitleDownloadController extends Controller
{
    /**
     * Download a generated subtitle file, named after the original media file.
     */
    public function __invoke(Upload $upload, string $format): StreamedResponse
    {
        abort_unless(
            $upload->status === UploadStatus::Ready && in_array($format, $upload->subtitleFormats(), true),
            404,
        );

        $disk = Storage::disk(config('uploads.disk'));
        $path = $upload->subtitlePath($format);

        abort_unless($disk->exists($path), 404);

        return $disk->download(
            $path,
            pathinfo($upload->original_name, PATHINFO_FILENAME).".{$format}",
            ['Content-Type' => $format === 'vtt' ? 'text/vtt; charset=UTF-8' : 'application/x-subrip; charset=UTF-8'],
        );
    }
}
