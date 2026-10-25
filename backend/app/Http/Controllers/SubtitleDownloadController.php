<?php

namespace App\Http\Controllers;

use App\Enums\UploadStatus;
use App\Models\Upload;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class SubtitleDownloadController extends Controller
{
    private const CONTENT_TYPES = [
        'srt' => 'application/x-subrip; charset=UTF-8',
        'vtt' => 'text/vtt; charset=UTF-8',
    ];

    /**
     * Download the generated subtitles, named after the original media file: the
     * one file, or a .zip with both when SRT and VTT were requested. The client
     * deletes the upload once it has the file.
     */
    public function __invoke(Upload $upload): StreamedResponse|BinaryFileResponse
    {
        abort_unless($upload->status === UploadStatus::Ready, 404);

        $disk = Storage::disk(config('uploads.disk'));
        $formats = $upload->subtitleFormats();

        foreach ($formats as $format) {
            abort_unless($disk->exists($upload->subtitlePath($format)), 404);
        }

        $name = pathinfo($upload->original_name, PATHINFO_FILENAME);

        if (count($formats) === 1) {
            return $disk->download(
                $upload->subtitlePath($formats[0]),
                "{$name}.{$formats[0]}",
                ['Content-Type' => self::CONTENT_TYPES[$formats[0]]],
            );
        }

        return response()
            ->download($this->zip($upload, $disk, $formats, $name), "{$name}.zip", ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /**
     * Write the subtitle files into a temporary zip and return its path.
     *
     * @param  list<string>  $formats
     */
    private function zip(Upload $upload, Filesystem $disk, array $formats, string $name): string
    {
        $path = tempnam(sys_get_temp_dir(), 'subtitles');
        $zip = new ZipArchive;

        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create a zip for upload [{$upload->id}].");
        }

        foreach ($formats as $format) {
            $zip->addFromString("{$name}.{$format}", $disk->get($upload->subtitlePath($format)));
        }

        $zip->close();

        return $path;
    }
}
