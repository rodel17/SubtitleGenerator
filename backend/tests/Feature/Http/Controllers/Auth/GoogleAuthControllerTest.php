<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;

beforeEach(function () {
    config([
        'app.frontend_url' => 'http://spa.test',
        'services.google.client_id' => 'client-id',
        'services.google.client_secret' => 'client-secret',
    ]);
});

/**
 * The API token the callback put in the SPA redirect's URL fragment.
 */
function tokenFromRedirect(string $location): string
{
    expect($location)->toStartWith('http://spa.test/auth/callback#token=');

    return rawurldecode(substr($location, strlen('http://spa.test/auth/callback#token=')));
}

function googleUser(array $attributes = []): GoogleUser
{
    return GoogleUser::fake([
        'id' => 'google-123',
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'avatar' => 'https://lh3.googleusercontent.com/ada',
        ...$attributes,
    ]);
}

describe('redirect', function () {
    it('sends the browser to Google', function () {
        Socialite::fake('google');

        $this->get('/auth/google/redirect')->assertRedirect('https://socialite.fake/google/authorize');
    });

    it('gives Google an https callback when HTTPS ends at the proxy in front of the app', function () {
        $location = $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://api.subgen.test/auth/google/redirect')
            ->headers->get('Location');

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        expect($query['redirect_uri'])->toBe('https://api.subgen.test/auth/google/callback');
    });

    it('returns to the sign-in page with a message when Google is not configured', function () {
        config(['services.google.client_id' => null]);

        $this->get('/auth/google/redirect')
            ->assertRedirect('http://spa.test/login?'.http_build_query(['error' => "Google sign-in isn't set up on the server yet."]));
    });
});

describe('callback', function () {
    it('creates the user on their first sign-in and hands the SPA a working API token', function () {
        Socialite::fake('google', googleUser());

        $response = $this->get('/auth/google/callback?code=abc&state=xyz');

        $user = User::sole();
        expect($user)
            ->google_id->toBe('google-123')
            ->name->toBe('Ada Lovelace')
            ->email->toBe('ada@example.com')
            ->avatar->toBe('https://lh3.googleusercontent.com/ada')
            ->password->toBeNull();

        $token = tokenFromRedirect($response->headers->get('Location'));
        expect(PersonalAccessToken::findToken($token)->tokenable->is($user))->toBeTrue();
    });

    it('signs a returning user in and refreshes their profile from Google', function () {
        $user = User::factory()->create(['google_id' => 'google-123', 'name' => 'Old Name', 'email' => 'old@example.com']);
        Socialite::fake('google', googleUser(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']));

        $this->get('/auth/google/callback?code=abc&state=xyz')->assertRedirect();

        expect(User::count())->toBe(1);
        expect($user->fresh())->name->toBe('Ada Lovelace')->email->toBe('ada@example.com');
    });

    it('links the Google account to an existing user with the same email', function () {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        Socialite::fake('google', googleUser());

        $this->get('/auth/google/callback?code=abc&state=xyz')->assertRedirect();

        expect(User::count())->toBe(1);
        expect($user->fresh()->google_id)->toBe('google-123');
    });

    it('falls back to the email as the name when the Google profile has none', function () {
        Socialite::fake('google', googleUser(['name' => null]));

        $this->get('/auth/google/callback?code=abc&state=xyz');

        expect(User::sole()->name)->toBe('ada@example.com');
    });

    it('returns to the sign-in page with a message when the user cancels on Google', function () {
        Socialite::fake('google', googleUser());

        $this->get('/auth/google/callback?error=access_denied&state=xyz')
            ->assertRedirect('http://spa.test/login?'.http_build_query(['error' => 'Google sign-in was cancelled.']));

        expect(User::count())->toBe(0);
    });

    it('returns to the sign-in page with a message when the sign-in session expired', function () {
        Socialite::fake('google', fn () => throw new InvalidStateException);

        $this->get('/auth/google/callback?code=abc&state=stale')
            ->assertRedirect('http://spa.test/login?'.http_build_query([
                'error' => 'Your sign-in took too long or was started in another tab. Please try again.',
            ]));

        expect(User::count())->toBe(0);
    });
});
