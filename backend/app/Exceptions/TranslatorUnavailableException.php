<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A translation engine can't be used right now (not configured, quota used up,
 * outage). The upload is then translated with another engine instead.
 */
class TranslatorUnavailableException extends RuntimeException
{
    //
}
