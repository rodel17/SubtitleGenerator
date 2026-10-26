<?php

namespace App\Enums;

/**
 * Emailing a ready upload's subtitles to its owner. Once sent, the upload is deleted.
 */
enum EmailStatus: string
{
    /** Queued or being sent. */
    case Sending = 'sending';

    /** Could not be sent; the reason is in the upload's `email_error`, and it can still be downloaded. */
    case Failed = 'failed';
}
