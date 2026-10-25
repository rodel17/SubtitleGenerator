<?php

use App\Models\Upload;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('returns 401 JSON for every protected route without a token', function (string $method, string $uri) {
    $upload = Upload::factory()->ready()->create();

    $this->json($method, str_replace('{upload}', $upload->id, $uri))
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
})->with([
    'current user' => ['GET', '/api/user'],
    'sign out' => ['POST', '/api/logout'],
    'list uploads' => ['GET', '/api/uploads'],
    'start upload' => ['POST', '/api/uploads'],
    'show upload' => ['GET', '/api/uploads/{upload}'],
    'delete upload' => ['DELETE', '/api/uploads/{upload}'],
    'list chunks' => ['GET', '/api/uploads/{upload}/chunks'],
    'send chunk' => ['POST', '/api/uploads/{upload}/chunks'],
    'complete upload' => ['POST', '/api/uploads/{upload}/completion'],
    'download subtitles' => ['GET', '/api/uploads/{upload}/subtitles'],
]);

it('returns 401 JSON even when the client does not ask for JSON', function () {
    $this->get('/api/uploads')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
});

it('returns 401 for a token older than the configured expiration', function () {
    config(['sanctum.expiration' => 60]);
    $token = User::factory()->create()->createToken('google');
    $token->accessToken->forceFill(['created_at' => now()->subMinutes(61)])->save();

    $this->withToken($token->plainTextToken)->getJson('/api/user')->assertUnauthorized();
});

it("returns the signed-in user's profile without Google or password fields", function () {
    $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'google_id' => 'g-1', 'avatar' => 'https://a.test/x']);
    Sanctum::actingAs($user);

    $this->getJson('/api/user')->assertOk()->assertExactJson(['data' => [
        'id' => $user->id,
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'avatar' => 'https://a.test/x',
    ]]);
});
