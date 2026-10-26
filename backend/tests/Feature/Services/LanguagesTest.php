<?php

use App\Services\Translation\Languages;

test('the app calls Filipino "fil", Whisper and NLLB call it Tagalog', function () {
    expect(Languages::normalize('fil'))->toBe('tl');
    expect(Languages::nllb('fil'))->toBe('tgl_Latn');
    expect(Languages::nllb('tl'))->toBe('tgl_Latn');
    expect(Languages::same('fil', 'tl'))->toBeTrue();
});

test('every target language maps to an NLLB code', function (string $language, string $code) {
    expect(Languages::nllb($language))->toBe($code);
})->with([
    'Filipino' => ['fil', 'tgl_Latn'],
    'English' => ['en', 'eng_Latn'],
    'Japanese' => ['ja', 'jpn_Jpan'],
    'Chinese (Simplified)' => ['zh', 'zho_Hans'],
    'Korean' => ['ko', 'kor_Hang'],
]);

test('every configured target can be translated into', function () {
    foreach (config('translation.targets') as $target) {
        expect(Languages::nllb($target))->not->toBeNull();
    }
});

test('an unknown or undetected language has no code', function (?string $language) {
    expect(Languages::nllb($language))->toBeNull();
})->with(['auto' => 'auto', 'empty' => '', 'none' => null, 'made up' => 'xx']);

test('an undetected language is never the same as any other, so it is never skipped', function (?string $language) {
    expect(Languages::same($language, $language))->toBeFalse();
    expect(Languages::same($language, 'fil'))->toBeFalse();
})->with(['auto' => 'auto', 'empty' => '', 'none' => null]);

test('Japanese and Chinese are written without spaces, the others with', function () {
    expect(Languages::writtenWithoutSpaces('ja'))->toBeTrue();
    expect(Languages::writtenWithoutSpaces('zh'))->toBeTrue();
    expect(Languages::writtenWithoutSpaces('ko'))->toBeFalse();
    expect(Languages::writtenWithoutSpaces('fil'))->toBeFalse();
    expect(Languages::writtenWithoutSpaces(null))->toBeFalse();
});
