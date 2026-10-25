<?php

namespace App\Enums;

enum UploadStatus: string
{
    /** Receiving chunks; can be resumed by sending the missing ones. */
    case Uploading = 'uploading';

    /** All chunks received; a job is joining them into the media file. */
    case Assembling = 'assembling';

    /** Media file assembled; waiting in the transcription queue. */
    case Uploaded = 'uploaded';

    /** Extracting audio, transcribing and writing subtitle files. */
    case Transcribing = 'transcribing';

    /** Subtitle files are waiting to be downloaded; everything else is already deleted. */
    case Ready = 'ready';

    /** Processing stopped and its files were deleted; the reason is in the upload's `error`. */
    case Failed = 'failed';

    /**
     * Whether a background job is working on the upload, so progress is reported.
     */
    public function isProcessing(): bool
    {
        return in_array($this, [self::Assembling, self::Transcribing], true);
    }
}
