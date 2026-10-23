<?php

use App\Models\User;

it('revokes only the token the request was made with', function () {
    $user = User::factory()->create();
    $current = $user->createToken('google');
    $otherDevice = $user->createToken('google');

    $this->withToken($current->plainTextToken)->postJson('/api/logout')->assertNoContent();

    $this->assertModelMissing($current->accessToken);
    $this->assertModelExists($otherDevice->accessToken);
});

it('returns 401 without a token', function () {
    $this->postJson('/api/logout')->assertUnauthorized();
});
