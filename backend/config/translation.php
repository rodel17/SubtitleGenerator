<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Target Languages
    |--------------------------------------------------------------------------
    |
    | What the subtitles can be translated into. Keep in step with
    | TRANSLATION_TARGETS in frontend/src/types/upload.ts.
    |
    */

    'targets' => ['fil', 'en', 'ja', 'zh', 'ko'],

    /*
    |--------------------------------------------------------------------------
    | Batch Size
    |--------------------------------------------------------------------------
    |
    | Sentences (Whisper segments) sent to the translator per request. Progress
    | is reported after each batch.
    |
    */

    'batch_size' => (int) env('TRANSLATION_BATCH_SIZE', 16),

    /*
    |--------------------------------------------------------------------------
    | Local Translator
    |--------------------------------------------------------------------------
    |
    | The "translator" container (NLLB-200 on CTranslate2, Docker/translator).
    | "timeout" is per request (one batch), in seconds.
    |
    */

    'local' => [
        'url' => env('TRANSLATOR_URL', 'http://translator:8000'),
        'timeout' => (int) env('TRANSLATOR_TIMEOUT', 300),
    ],

];
