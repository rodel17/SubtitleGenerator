<?php

use App\Enums\UploadStatus;
use App\Models\Upload;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['uploads.chunk_size' => 20]);
});

describe('index', function () {
    it('lists uploads newest first', function () {
        [$older, $newer] = Upload::factory()->count(2)->sequence(
            ['created_at' => now()->subHour()],
            ['created_at' => now()],
        )->create();

        $response = $this->getJson('/api/uploads');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    });

    it('returns only the 20 most recent uploads', function () {
        $oldest = Upload::factory()->create(['created_at' => now()->subDay()]);
        Upload::factory()->count(20)->create();

        $response = $this->getJson('/api/uploads');

        $response->assertOk()->assertJsonCount(20, 'data');
        expect(collect($response->json('data'))->pluck('id'))->not->toContain($oldest->id);
    });

    it('returns an empty list when there are no uploads', function () {
        $this->getJson('/api/uploads')->assertOk()->assertExactJson(['data' => []]);
    });
});

describe('show', function () {
    it('returns the upload with exactly the documented fields', function () {
        $upload = Upload::factory()->uploaded()->create([
            'original_name' => 'Interview.mp4',
            'size' => 45,
            'chunk_size' => 20,
            'total_chunks' => 3,
            'language' => 'en',
            'subtitle_format' => 'vtt',
        ]);

        $response = $this->getJson("/api/uploads/{$upload->id}");

        $response->assertOk()
            ->assertExactJsonStructure(['data' => [
                'id', 'name', 'extension', 'mime_type', 'size', 'chunk_size', 'total_chunks',
                'language', 'subtitle_format', 'status', 'created_at', 'completed_at',
            ]])
            ->assertJsonPath('data.name', 'Interview.mp4')
            ->assertJsonPath('data.size', 45)
            ->assertJsonPath('data.language', 'en')
            ->assertJsonPath('data.subtitle_format', 'vtt')
            ->assertJsonPath('data.status', 'uploaded');
    });

    it('does not expose the storage path', function () {
        $upload = Upload::factory()->uploaded()->create();

        $this->getJson("/api/uploads/{$upload->id}")->assertJsonMissingPath('data.path');
    });

    it('returns 404 for an unknown or malformed id', function (string $id) {
        $this->getJson("/api/uploads/{$id}")->assertNotFound();
    })->with([
        'unknown uuid' => '01a15979-96db-7264-a6ec-88c51a111e09',
        'not a uuid' => 'not-a-uuid',
        'numeric id' => '1',
        'sql injection attempt' => "1' OR '1'='1",
    ]);
});

describe('store', function () {
    it('creates an upload and returns how to split the file', function () {
        $response = $this->postJson('/api/uploads', [
            'name' => 'Team Interview.mp4',
            'size' => 45,
            'mime_type' => 'video/mp4',
            'language' => 'en',
            'subtitle_format' => 'vtt',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Team Interview.mp4')
            ->assertJsonPath('data.extension', 'mp4')
            ->assertJsonPath('data.size', 45)
            ->assertJsonPath('data.chunk_size', 20)
            ->assertJsonPath('data.total_chunks', 3)
            ->assertJsonPath('data.status', 'uploading')
            ->assertJsonPath('data.completed_at', null);
        $this->assertDatabaseHas('uploads', [
            'id' => $response->json('data.id'),
            'original_name' => 'Team Interview.mp4',
            'language' => 'en',
            'subtitle_format' => 'vtt',
            'status' => 'uploading',
            'path' => null,
        ]);
    });

    it('calculates the number of chunks from the file size', function (int $size, int $expectedChunks) {
        $response = $this->postJson('/api/uploads', [
            'name' => 'clip.mp4',
            'size' => $size,
            'subtitle_format' => 'srt',
        ]);

        $response->assertCreated()->assertJsonPath('data.total_chunks', $expectedChunks);
    })->with([
        'one byte' => [1, 1],
        'exactly one chunk' => [20, 1],
        'one byte over a chunk' => [21, 2],
        'exactly two chunks' => [40, 2],
        'partial third chunk' => [45, 3],
    ]);

    it('accepts every supported extension, case-insensitively', function (string $name, string $extension) {
        $response = $this->postJson('/api/uploads', [
            'name' => $name,
            'size' => 10,
            'subtitle_format' => 'srt',
        ]);

        $response->assertCreated()->assertJsonPath('data.extension', $extension);
    })->with([
        ['clip.mp4', 'mp4'],
        ['clip.mov', 'mov'],
        ['clip.mkv', 'mkv'],
        ['clip.ts', 'ts'],
        ['clip.mp3', 'mp3'],
        ['clip.wav', 'wav'],
        ['clip.m4a', 'm4a'],
        ['CLIP.MP4', 'mp4'],
        ['my.holiday.video.Mov', 'mov'],
    ]);

    it('allows language and mime type to be omitted', function () {
        $response = $this->postJson('/api/uploads', [
            'name' => 'clip.mp3',
            'size' => 10,
            'subtitle_format' => 'both',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.language', null)
            ->assertJsonPath('data.mime_type', null);
    });

    it('returns 422 with every required field when the payload is empty', function () {
        $response = $this->postJson('/api/uploads', []);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'name' => 'The name field is required.',
            'size' => 'The size field is required.',
            'subtitle_format' => 'The subtitle format field is required.',
        ]);
        $this->assertDatabaseCount('uploads', 0);
    });

    it('returns 422 for an invalid field value', function (array $override, string $field, string $message) {
        $payload = array_merge(['name' => 'clip.mp4', 'size' => 10, 'subtitle_format' => 'srt'], $override);

        $response = $this->postJson('/api/uploads', $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);
        $this->assertDatabaseCount('uploads', 0);
    })->with([
        'name too long' => [['name' => str_repeat('a', 252).'.mp4'], 'name', 'The name field must not be greater than 255 characters.'],
        'name not a string' => [['name' => ['clip.mp4']], 'name', 'The name field must be a string.'],
        'size zero' => [['size' => 0], 'size', 'The size field must be at least 1.'],
        'size negative' => [['size' => -5], 'size', 'The size field must be at least 1.'],
        'size not an integer' => [['size' => 'big'], 'size', 'The size field must be an integer.'],
        'size fractional' => [['size' => 10.5], 'size', 'The size field must be an integer.'],
        'size over 2 GB' => [['size' => 2 * 1024 ** 3 + 1], 'size', 'The file is larger than the 2 GB limit.'],
        'unknown subtitle format' => [['subtitle_format' => 'ass'], 'subtitle_format', 'The selected subtitle format is invalid.'],
        'language too long' => [['language' => 'english-uk'.'x'], 'language', 'The language field must not be greater than 10 characters.'],
        'mime type too long' => [['mime_type' => str_repeat('a', 256)], 'mime_type', 'The mime type field must not be greater than 255 characters.'],
    ]);

    it('accepts a file of exactly the maximum size', function () {
        $response = $this->postJson('/api/uploads', [
            'name' => 'clip.mp4',
            'size' => 2 * 1024 ** 3,
            'subtitle_format' => 'srt',
        ]);

        $response->assertCreated();
    });

    it('returns 422 for a file name without a supported extension', function (string $name) {
        $response = $this->postJson('/api/uploads', [
            'name' => $name,
            'size' => 10,
            'subtitle_format' => 'srt',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'name' => 'Unsupported format. Allowed: MP4, MOV, MKV, TS, MP3, WAV, M4A.',
        ]);
        $this->assertDatabaseCount('uploads', 0);
    })->with([
        'text file' => 'notes.txt',
        'no extension' => 'video',
        'trailing dot' => 'video.',
        'media extension in the middle' => 'clip.mp4.zip',
        'php script' => 'shell.php',
        'webm' => 'screen-recording.webm',
    ]);

    it('ignores server-controlled fields sent by the client', function () {
        $response = $this->postJson('/api/uploads', [
            'name' => 'clip.mp4',
            'size' => 45,
            'subtitle_format' => 'srt',
            'id' => '01a15979-96db-7264-a6ec-88c51a111e09',
            'status' => 'uploaded',
            'path' => '../../../.env',
            'chunk_size' => 1,
            'total_chunks' => 1,
            'completed_at' => '2026-01-01 00:00:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'uploading')
            ->assertJsonPath('data.chunk_size', 20)
            ->assertJsonPath('data.total_chunks', 3)
            ->assertJsonPath('data.completed_at', null);
        expect($response->json('data.id'))->not->toBe('01a15979-96db-7264-a6ec-88c51a111e09');
        $this->assertDatabaseHas('uploads', ['id' => $response->json('data.id'), 'path' => null]);
    });
});

describe('destroy', function () {
    it('deletes an in-progress upload and its received chunks', function () {
        Storage::fake('local');
        $bytes = wavBytes();
        $upload = Upload::factory()->forContent($bytes)->create();
        sendAllChunks($upload, substr($bytes, 0, 40));

        $response = $this->deleteJson("/api/uploads/{$upload->id}");

        $response->assertNoContent();
        $this->assertModelMissing($upload);
        Storage::disk('local')->assertMissing("chunks/{$upload->id}");
    });

    it('deletes a completed upload and its media file', function () {
        Storage::fake('local');
        $upload = Upload::factory()->uploaded()->create();
        Storage::disk('local')->put($upload->path, 'media');

        $response = $this->deleteJson("/api/uploads/{$upload->id}");

        $response->assertNoContent();
        $this->assertModelMissing($upload);
        Storage::disk('local')->assertMissing($upload->path);
    });

    it('leaves other uploads and their files untouched', function () {
        Storage::fake('local');
        [$deleted, $kept] = Upload::factory()->uploaded()->count(2)->create();
        Storage::disk('local')->put($deleted->path, 'media');
        Storage::disk('local')->put($kept->path, 'media');

        $this->deleteJson("/api/uploads/{$deleted->id}")->assertNoContent();

        $this->assertModelExists($kept);
        Storage::disk('local')->assertExists($kept->path);
    });

    it('returns 404 for an unknown upload', function () {
        $this->deleteJson('/api/uploads/01a15979-96db-7264-a6ec-88c51a111e09')->assertNotFound();
    });

    it('returns 404 when the upload was already deleted', function () {
        Storage::fake('local');
        $upload = Upload::factory()->create();
        $this->deleteJson("/api/uploads/{$upload->id}")->assertNoContent();

        $this->deleteJson("/api/uploads/{$upload->id}")->assertNotFound();
    });
});

it('rejects the update method because uploads cannot be edited', function () {
    $upload = Upload::factory()->create(['status' => UploadStatus::Uploading]);

    $this->putJson("/api/uploads/{$upload->id}", ['status' => 'uploaded'])->assertMethodNotAllowed();
});
