<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A processing failure caused by the media itself (no audio, no speech, …).
 * The message is shown to the user, and retrying the job will not help.
 */
class TranscriptionException extends RuntimeException
{
    //
}
