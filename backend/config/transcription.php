<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Whisper Service
    |--------------------------------------------------------------------------
    |
    | The local speech-to-text service (the "whisper" container, running
    | faster-whisper). "timeout" is per request, in seconds; each request
    | carries one piece of audio (see "piece_seconds").
    |
    */

    'whisper' => [
        'url' => env('WHISPER_URL', 'http://whisper:9000'),
        'timeout' => (int) env('WHISPER_TIMEOUT', 1200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio Pieces
    |--------------------------------------------------------------------------
    |
    | Extracted audio is split into pieces of this many seconds and sent to
    | Whisper one at a time, so progress can be reported after each piece
    | and no single request runs for the length of a whole film.
    |
    */

    'piece_seconds' => (int) env('TRANSCRIPTION_PIECE_SECONDS', 300),

];
