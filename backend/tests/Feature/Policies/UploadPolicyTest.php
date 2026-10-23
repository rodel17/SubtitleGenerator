<?php

use App\Models\Upload;
use App\Models\User;
use App\Policies\UploadPolicy;

test('the owner may view, update and delete their upload', function (string $ability) {
    $upload = Upload::factory()->create();

    expect((new UploadPolicy)->{$ability}($upload->user, $upload)->allowed())->toBeTrue();
})->with(['view', 'update', 'delete']);

test("another user is refused with 404 for someone else's upload", function (string $ability) {
    $upload = Upload::factory()->create();

    $response = (new UploadPolicy)->{$ability}(User::factory()->create(), $upload);

    expect($response)->denied()->toBeTrue()->status()->toBe(404);
})->with(['view', 'update', 'delete']);

test('nobody may touch an upload made before sign-in existed', function (string $ability) {
    $upload = Upload::factory()->create(['user_id' => null]);

    expect((new UploadPolicy)->{$ability}(User::factory()->create(), $upload)->denied())->toBeTrue();
})->with(['view', 'update', 'delete']);
