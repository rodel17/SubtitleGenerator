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

    /*
    |--------------------------------------------------------------------------
    | Azure Translator ("Enhanced")
    |--------------------------------------------------------------------------
    |
    | Offered only when a key and region are set. Free F0 tier: 2M characters
    | a month, ~33k a minute. The Asia Pacific endpoint keeps processing in
    | Japan East / Southeast Asia. Microsoft doesn't store the text ("No Trace").
    | When Azure can't be used (quota, outage) the local translator takes over.
    |
    */

    'azure' => [
        'key' => env('AZURE_TRANSLATOR_KEY'),
        'region' => env('AZURE_TRANSLATOR_REGION'),
        'endpoint' => env('AZURE_TRANSLATOR_ENDPOINT', 'https://api-apc.cognitive.microsofttranslator.com'),
        'timeout' => (int) env('AZURE_TRANSLATOR_TIMEOUT', 60),
    ],

];
