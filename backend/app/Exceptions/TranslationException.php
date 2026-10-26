<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A translation that can't be done (unknown or unsupported spoken language).
 * The message is shown to the user, and retrying the job will not help.
 */
class TranslationException extends RuntimeException
{
    //
}
