<?php

use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('stores a chunk and leaves the upload in progress', function () {
    Storage::fake('local');
    $upload = Upload::factory()->for($this->user)->forContent(str_repeat('a', 45))->create();

    $response = postChunk($upload, 1, str_repeat('b', 20));

    $response->assertNoContent();
    expect(Storage::disk('local')->get("chunks/{$upload->id}/1"))->toBe(str_repeat('b', 20));
    expect($upload->fresh()->isUploading())->toBeTrue();
});

it('accepts a last chunk that is shorter than the chunk size', function () {
    Storage::fake('local');
    $upload = Upload::factory()->for($this->user)->forContent(str_repeat('a', 45))->create();

    $response = postChunk($upload, 2, 'ccccc');

    $response->assertNoContent();
    expect(Storage::disk('local')->get("chunks/{$upload->id}/2"))->toBe('ccccc');
});

it('replaces a chunk that is sent again', function () {
    Storage::fake('local');
    $upload = Upload::factory()->for($this->user)->forContent(str_repeat('a', 45))->create();
    postChunk($upload, 0, str_repeat('x', 20))->assertNoContent();

    $response = postChunk($upload, 0, str_repeat('y', 20));

    $response->assertNoContent();
    expect(Storage::disk('local')->get("chunks/{$upload->id}/0"))->toBe(str_repeat('y', 20));
    expect(Storage::disk('local')->files("chunks/{$upload->id}"))->toHaveCount(1);
});

it('validates chunk sizes against the size stored on the upload, not the current config', function () {
    Storage::fake('local');
    $upload = Upload::factory()->for($this->user)->forContent(str_repeat('a', 45), chunkSize: 20)->create();
    config(['uploads.chunk_size' => 5]);

    $response = postChunk($upload, 0, str_repeat('a', 20));

    $response->assertNoContent();
});

it('returns 422 for both fields when the payload is empty', function () {
    $upload = Upload::factory()->for($this->user)->forContent(str_repeat('a', 45))->create();

    $response = $this->postJson("/api/uploads/{$upload->id}/chunks", []);

    $response->assertUnprocessable()->assertJsonValidationErrors([
        'index' => 'The index field is required.',
        'chunk' => 'The chunk field is required.',
    ]);
});

it('returns 422 for an index outside the upload', function (mixed $index, string $message) {
    Storage::fake('local');
    $upload = Upload::factory()->for($this->user)->forContent(str_repeat('a', 45))->create();

    $response = postChunk($upload, $index, str_repeat('a', 20));

    $response->assertUnprocessable()->assertJsonValidationErrors(['index' => $message]);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
})->with([
    'negative' => [-1, 'The index field must be at least 0.'],
    'one past the last chunk' => [3, 'The index field must not be greater than 2.'],
    'not a number' => ['first', 'The index field must be an integer.'],
    'fractional' => ['1.5', 'The index field must be an integer.'],
]);

it('returns 422 when the chunk is not a file', function () {
    $upload = Upload::factory()->for($this->user)->forContent(str_repeat('a', 45))->create();

    $response = $this->postJson("/api/uploads/{$upload->id}/chunks", [
        'index' => 0,
        'chunk' => str_repeat('a', 20),
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors([
        'chunk' => 'The chunk field must be a file.',
    ]);
});

it('returns 422 when a chunk is not exactly the expected size', function (int $index, int $bytes, string $message) {
    Storage::fake('local');
    $upload = Upload::factory()->for($this->user)->forContent(str_repeat('a', 45))->create();

    $response = postChunk($upload, $index, str_repeat('a', $bytes));

    $response->assertUnprocessable()->assertJsonValidationErrors(['chunk' => $message]);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
})->with([
    'truncated middle chunk' => [1, 19, 'Chunk must be 20 bytes, got 19.'],
    'oversized middle chunk' => [1, 21, 'Chunk must be 20 bytes, got 21.'],
    'full-size last chunk' => [2, 20, 'Chunk must be 5 bytes, got 20.'],
    'truncated last chunk' => [2, 4, 'Chunk must be 5 bytes, got 4.'],
]);

it('returns 409 for an upload that is already complete', function () {
    Storage::fake('local');
    $upload = Upload::factory()->for($this->user)->uploaded()->create(['size' => 20, 'chunk_size' => 20, 'total_chunks' => 1]);

    $response = postChunk($upload, 0, str_repeat('a', 20));

    $response->assertConflict()->assertJsonPath('message', 'This upload is already complete.');
    Storage::disk('local')->assertMissing("chunks/{$upload->id}/0");
});

it('returns 404 for an unknown upload', function () {
    $response = $this->postJson('/api/uploads/01a15979-96db-7264-a6ec-88c51a111e09/chunks', ['index' => 0]);

    $response->assertNotFound();
});

describe('index', function () {
    it('lists the chunks already received, so an interrupted upload can resume', function () {
        Storage::fake('local');
        $upload = Upload::factory()->for($this->user)->forContent(str_repeat('a', 45))->create();
        postChunk($upload, 2, str_repeat('c', 5));
        postChunk($upload, 0, str_repeat('a', 20));

        $response = $this->getJson("/api/uploads/{$upload->id}/chunks");

        $response->assertOk()->assertExactJson(['data' => [
            'chunk_size' => 20,
            'total_chunks' => 3,
            'received' => [0, 2],
        ]]);
    });

    it('leaves out a stored chunk with the wrong size, so the client sends it again', function () {
        Storage::fake('local');
        $upload = Upload::factory()->for($this->user)->forContent(str_repeat('a', 45))->create();
        postChunk($upload, 0, str_repeat('a', 20));
        Storage::disk('local')->put("chunks/{$upload->id}/1", 'truncated');

        $this->getJson("/api/uploads/{$upload->id}/chunks")->assertJsonPath('data.received', [0]);
    });

    it('ignores files that are not chunks of this upload', function () {
        Storage::fake('local');
        $upload = Upload::factory()->for($this->user)->forContent(str_repeat('a', 45))->create();
        Storage::disk('local')->put("chunks/{$upload->id}/notes", str_repeat('a', 20));
        Storage::disk('local')->put("chunks/{$upload->id}/3", str_repeat('a', 20));

        $this->getJson("/api/uploads/{$upload->id}/chunks")->assertJsonPath('data.received', []);
    });

    it('returns an empty list before any chunk arrives', function () {
        Storage::fake('local');
        $upload = Upload::factory()->for($this->user)->forContent(str_repeat('a', 45))->create();

        $this->getJson("/api/uploads/{$upload->id}/chunks")->assertJsonPath('data.received', []);
    });

    it('returns 409 once the upload is complete', function () {
        $upload = Upload::factory()->for($this->user)->uploaded()->create();

        $this->getJson("/api/uploads/{$upload->id}/chunks")
            ->assertConflict()
            ->assertJsonPath('message', 'This upload is already complete.');
    });

    it('returns 404 for an unknown upload', function () {
        $this->getJson('/api/uploads/01a15979-96db-7264-a6ec-88c51a111e09/chunks')->assertNotFound();
    });
});

it("returns 404 for another user's upload and stores nothing", function () {
    Storage::fake('local');
    $upload = Upload::factory()->forContent(str_repeat('a', 45))->create();

    postChunk($upload, 0, str_repeat('a', 20))->assertNotFound();

    Storage::disk('local')->assertMissing("chunks/{$upload->id}");
});

it("returns 404 when listing the chunks of another user's upload", function () {
    $upload = Upload::factory()->forContent(str_repeat('a', 45))->create();

    $this->getJson("/api/uploads/{$upload->id}/chunks")->assertNotFound();
});
