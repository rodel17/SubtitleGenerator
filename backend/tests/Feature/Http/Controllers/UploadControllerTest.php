<?php

use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    config(['uploads.chunk_size' => 20]);
});

describe('index', function () {
    it('lists uploads newest first', function () {
        [$older, $newer] = Upload::factory()->for($this->user)->count(2)->sequence(
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
        $oldest = Upload::factory()->for($this->user)->create(['created_at' => now()->subDay()]);
        Upload::factory()->for($this->user)->count(20)->create();

        $response = $this->getJson('/api/uploads');

        $response->assertOk()->assertJsonCount(20, 'data');
        expect(collect($response->json('data'))->pluck('id'))->not->toContain($oldest->id);
    });

    it("does not list other users' uploads", function () {
        $mine = Upload::factory()->for($this->user)->create();
        Upload::factory()->create();
        Upload::factory()->create(['user_id' => null]);

        $this->getJson('/api/uploads')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    });

    it('returns an empty list when there are no uploads', function () {
        $this->getJson('/api/uploads')->assertOk()->assertExactJson(['data' => []]);
    });
});

describe('show', function () {
    it('returns the upload with exactly the documented fields', function () {
        $upload = Upload::factory()->for($this->user)->uploaded()->create([
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
                'id', 'name', 'extension', 'mime_type', 'last_modified', 'size', 'chunk_size', 'total_chunks',
                'language', 'translate_to', 'translation_engine', 'translated_with', 'subtitle_format', 'email_when_ready', 'status',
                'progress', 'error', 'email_status', 'email_error', 'created_at', 'completed_at', 'expires_at',
            ]])
            ->assertJsonPath('data.name', 'Interview.mp4')
            ->assertJsonPath('data.size', 45)
            ->assertJsonPath('data.language', 'en')
            ->assertJsonPath('data.subtitle_format', 'vtt')
            ->assertJsonPath('data.status', 'uploaded');
    });

    it('reports the progress of the current background step', function (UploadStatus $status) {
        $upload = Upload::factory()->for($this->user)->uploaded()->create(['status' => $status]);
        $upload->reportProgress(42);

        $this->getJson("/api/uploads/{$upload->id}")->assertJsonPath('data.progress', 42);
    })->with([
        'assembling' => UploadStatus::Assembling,
        'transcribing' => UploadStatus::Transcribing,
    ]);

    it('reports no progress when nothing is running', function (UploadStatus $status) {
        $upload = Upload::factory()->for($this->user)->uploaded()->create(['status' => $status]);
        $upload->reportProgress(42);

        $this->getJson("/api/uploads/{$upload->id}")->assertJsonPath('data.progress', null);
    })->with([
        'uploading' => UploadStatus::Uploading,
        'waiting to transcribe' => UploadStatus::Uploaded,
        'ready' => UploadStatus::Ready,
        'failed' => UploadStatus::Failed,
    ]);

    it('explains why processing failed', function () {
        Storage::fake('local');
        $upload = Upload::factory()->for($this->user)->uploaded()->create();
        $upload->markFailed('No speech was detected in this file.');

        $this->getJson("/api/uploads/{$upload->id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'No speech was detected in this file.');
    });

    it('links the subtitle download once ready', function () {
        $upload = Upload::factory()->for($this->user)->ready()->create(['subtitle_format' => 'both']);

        $this->getJson("/api/uploads/{$upload->id}")
            ->assertJsonPath('data.download_url', url("/api/uploads/{$upload->id}/subtitles"));
    });

    it('has no download link before the upload is ready', function () {
        $upload = Upload::factory()->for($this->user)->uploaded()->create(['status' => UploadStatus::Transcribing]);

        $this->getJson("/api/uploads/{$upload->id}")->assertJsonMissingPath('data.download_url');
    });

    it('says when a finished upload will be deleted if nobody downloads it', function () {
        $this->freezeSecond();
        config(['uploads.retention_hours' => 6]);
        $upload = Upload::factory()->for($this->user)->ready()->create(['finished_at' => now()]);

        $this->getJson("/api/uploads/{$upload->id}")
            ->assertJsonPath('data.expires_at', now()->addHours(6)->toJSON());
    });

    it('has no deletion time while the upload is still being processed', function () {
        $upload = Upload::factory()->for($this->user)->uploaded()->create(['status' => UploadStatus::Transcribing]);

        $this->getJson("/api/uploads/{$upload->id}")->assertJsonPath('data.expires_at', null);
    });

    it('does not expose the storage path', function () {
        $upload = Upload::factory()->for($this->user)->uploaded()->create();

        $this->getJson("/api/uploads/{$upload->id}")->assertJsonMissingPath('data.path');
    });

    it("returns 404 for another user's upload", function () {
        $upload = Upload::factory()->create();

        $this->getJson("/api/uploads/{$upload->id}")->assertNotFound();
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
            'user_id' => $this->user->id,
            'original_name' => 'Team Interview.mp4',
            'language' => 'en',
            'subtitle_format' => 'vtt',
            'status' => 'uploading',
            'path' => null,
        ]);
    });

    it('returns 429 with a readable message once the hourly upload limit is reached', function () {
        config(['uploads.max_per_hour' => 2]);
        $payload = ['name' => 'clip.mp4', 'size' => 45, 'subtitle_format' => 'srt'];
        $this->postJson('/api/uploads', $payload)->assertCreated();
        $this->postJson('/api/uploads', $payload)->assertCreated();

        $this->postJson('/api/uploads', $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'You can start up to 2 uploads per hour. Please try again later.');

        expect(Upload::count())->toBe(2);
    });

    it('counts the hourly upload limit per user', function () {
        config(['uploads.max_per_hour' => 1]);
        $payload = ['name' => 'clip.mp4', 'size' => 45, 'subtitle_format' => 'srt'];
        $this->postJson('/api/uploads', $payload)->assertCreated();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/uploads', $payload)->assertCreated();
    });

    it("names an upload of a video's extracted audio after the video", function () {
        $response = $this->postJson('/api/uploads', [
            'name' => 'Team Interview.mka',
            'display_name' => 'Team Interview.mp4',
            'size' => 45,
            'subtitle_format' => 'srt',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Team Interview.mp4')
            ->assertJsonPath('data.extension', 'mka');
        expect(Upload::sole()->subtitleFileName('srt'))->toBe('Team Interview.srt');
    });

    it('names the upload after the file when no display name is sent', function () {
        $this->postJson('/api/uploads', ['name' => 'clip.mka', 'display_name' => null, 'size' => 45, 'subtitle_format' => 'srt'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'clip.mka');
    });

    it('keeps the file modification time so a resumed upload can be matched to its file', function () {
        $response = $this->postJson('/api/uploads', [
            'name' => 'clip.mp4',
            'size' => 45,
            'last_modified' => 1792500000123,
            'subtitle_format' => 'srt',
        ]);

        $response->assertCreated()->assertJsonPath('data.last_modified', 1792500000123);
    });

    it('remembers whether to email the subtitles once they are ready', function (?bool $emailWhenReady, bool $expected) {
        $response = $this->postJson('/api/uploads', array_filter([
            'name' => 'clip.mp4',
            'size' => 45,
            'subtitle_format' => 'srt',
            'email_when_ready' => $emailWhenReady,
        ], fn ($value) => $value !== null));

        $response->assertCreated()
            ->assertJsonPath('data.email_when_ready', $expected)
            ->assertJsonPath('data.email_status', null);
    })->with([
        'chosen' => [true, true],
        'declined' => [false, false],
        'not sent' => [null, false],
    ]);

    it('remembers the language to translate into, translated on our own server', function () {
        $this->postJson('/api/uploads', ['name' => 'clip.mp4', 'size' => 45, 'subtitle_format' => 'srt', 'translate_to' => 'fil'])
            ->assertCreated()
            ->assertJsonPath('data.translate_to', 'fil')
            ->assertJsonPath('data.translation_engine', 'local');
    });

    it('does not translate unless asked', function () {
        $this->postJson('/api/uploads', ['name' => 'clip.mp4', 'size' => 45, 'subtitle_format' => 'srt'])
            ->assertCreated()
            ->assertJsonPath('data.translate_to', null)
            ->assertJsonPath('data.translation_engine', null);
    });

    it('translates with Enhanced (Azure) when chosen and configured', function () {
        config(['translation.azure.key' => 'test-key', 'translation.azure.region' => 'southeastasia']);

        $this->postJson('/api/uploads', ['name' => 'clip.mp4', 'size' => 45, 'subtitle_format' => 'srt', 'translate_to' => 'ja', 'translation_engine' => 'azure'])
            ->assertCreated()
            ->assertJsonPath('data.translation_engine', 'azure')
            ->assertJsonPath('data.translated_with', null);
    });

    it('refuses Enhanced when Azure is not configured', function () {
        config(['translation.azure.key' => null]);

        $this->postJson('/api/uploads', ['name' => 'clip.mp4', 'size' => 45, 'subtitle_format' => 'srt', 'translate_to' => 'ja', 'translation_engine' => 'azure'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['translation_engine']);
    });

    it('rejects a language it cannot translate into', function (string $language) {
        $this->postJson('/api/uploads', ['name' => 'clip.mp4', 'size' => 45, 'subtitle_format' => 'srt', 'translate_to' => $language])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['translate_to']);
    })->with(['not offered' => 'fr', 'made up' => 'klingon']);

    it('rejects an email choice that is not a boolean', function () {
        $this->postJson('/api/uploads', ['name' => 'clip.mp4', 'size' => 45, 'subtitle_format' => 'srt', 'email_when_ready' => 'please'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email_when_ready']);
    });

    it('rejects an invalid modification time', function (mixed $lastModified) {
        $response = $this->postJson('/api/uploads', [
            'name' => 'clip.mp4',
            'size' => 45,
            'last_modified' => $lastModified,
            'subtitle_format' => 'srt',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['last_modified']);
    })->with(['negative' => -1, 'text' => 'yesterday', 'decimal' => 1.5]);

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
        ['clip.mka', 'mka'],
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
        'size over 500 MB' => [['size' => 500 * 1024 ** 2 + 1], 'size', 'The file is larger than the 500 MB limit.'],
        'unknown subtitle format' => [['subtitle_format' => 'ass'], 'subtitle_format', 'The selected subtitle format is invalid.'],
        'language too long' => [['language' => 'english-uk'.'x'], 'language', 'The language field must not be greater than 10 characters.'],
        'mime type too long' => [['mime_type' => str_repeat('a', 256)], 'mime_type', 'The mime type field must not be greater than 255 characters.'],
        'display name too long' => [['display_name' => str_repeat('a', 256)], 'display_name', 'The display name field must not be greater than 255 characters.'],
        'display name not a string' => [['display_name' => ['clip.mp4']], 'display_name', 'The display name field must be a string.'],
    ]);

    it('accepts a file of exactly the maximum size', function () {
        $response = $this->postJson('/api/uploads', [
            'name' => 'clip.mp4',
            'size' => 500 * 1024 ** 2,
            'subtitle_format' => 'srt',
        ]);

        $response->assertCreated();
    });

    it('states a limit of 1 GB or more in GB', function () {
        config(['uploads.max_size' => 2 * 1024 ** 3]);

        $response = $this->postJson('/api/uploads', ['name' => 'clip.mp4', 'size' => 2 * 1024 ** 3 + 1, 'subtitle_format' => 'srt']);

        $response->assertUnprocessable()->assertJsonValidationErrors(['size' => 'The file is larger than the 2 GB limit.']);
    });

    it('returns 422 for a file name without a supported extension', function (string $name) {
        $response = $this->postJson('/api/uploads', [
            'name' => $name,
            'size' => 10,
            'subtitle_format' => 'srt',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'name' => 'Unsupported format. Allowed: MP4, MOV, MKV, TS, MP3, WAV, M4A, MKA.',
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
        $upload = Upload::factory()->for($this->user)->forContent($bytes)->create();
        sendAllChunks($upload, substr($bytes, 0, 40));

        $response = $this->deleteJson("/api/uploads/{$upload->id}");

        $response->assertNoContent();
        $this->assertModelMissing($upload);
        Storage::disk('local')->assertMissing("chunks/{$upload->id}");
    });

    it('deletes a completed upload and its media file', function () {
        Storage::fake('local');
        $upload = Upload::factory()->for($this->user)->uploaded()->create();
        Storage::disk('local')->put($upload->path, 'media');

        $response = $this->deleteJson("/api/uploads/{$upload->id}");

        $response->assertNoContent();
        $this->assertModelMissing($upload);
        Storage::disk('local')->assertMissing($upload->path);
    });

    it('deletes the subtitles once they have been downloaded', function () {
        Storage::fake('local');
        $upload = Upload::factory()->for($this->user)->ready()->create(['subtitle_format' => 'both']);
        $files = [$upload->subtitlePath('srt'), $upload->subtitlePath('vtt')];
        foreach ($files as $file) {
            Storage::disk('local')->put($file, 'x');
        }

        $this->deleteJson("/api/uploads/{$upload->id}")->assertNoContent();

        $this->assertModelMissing($upload);
        Storage::disk('local')->assertMissing($files);
    });

    it('deletes every file of an upload that is still being transcribed', function () {
        Storage::fake('local');
        $upload = Upload::factory()->for($this->user)->uploaded()->create(['status' => UploadStatus::Transcribing]);
        $files = [
            $upload->path,
            $upload->transcriptPath(),
            $upload->subtitlePath('srt'),
            "{$upload->audioDirectory()}/0000.wav",
        ];
        foreach ($files as $file) {
            Storage::disk('local')->put($file, 'x');
        }

        $this->deleteJson("/api/uploads/{$upload->id}")->assertNoContent();

        Storage::disk('local')->assertMissing($files);
        Storage::disk('local')->assertMissing($upload->audioDirectory());
    });

    it('leaves other uploads and their files untouched', function () {
        Storage::fake('local');
        [$deleted, $kept] = Upload::factory()->for($this->user)->uploaded()->count(2)->create();
        Storage::disk('local')->put($deleted->path, 'media');
        Storage::disk('local')->put($kept->path, 'media');

        $this->deleteJson("/api/uploads/{$deleted->id}")->assertNoContent();

        $this->assertModelExists($kept);
        Storage::disk('local')->assertExists($kept->path);
    });

    it('returns 404 for an unknown upload', function () {
        $this->deleteJson('/api/uploads/01a15979-96db-7264-a6ec-88c51a111e09')->assertNotFound();
    });

    it("returns 404 for another user's upload and keeps it", function () {
        Storage::fake('local');
        $upload = Upload::factory()->uploaded()->create();
        Storage::disk('local')->put($upload->path, 'media');

        $this->deleteJson("/api/uploads/{$upload->id}")->assertNotFound();

        $this->assertModelExists($upload);
        Storage::disk('local')->assertExists($upload->path);
    });

    it('returns 404 when the upload was already deleted', function () {
        Storage::fake('local');
        $upload = Upload::factory()->for($this->user)->create();
        $this->deleteJson("/api/uploads/{$upload->id}")->assertNoContent();

        $this->deleteJson("/api/uploads/{$upload->id}")->assertNotFound();
    });
});

it('rejects the update method because uploads cannot be edited', function () {
    $upload = Upload::factory()->for($this->user)->create(['status' => UploadStatus::Uploading]);

    $this->putJson("/api/uploads/{$upload->id}", ['status' => 'uploaded'])->assertMethodNotAllowed();
});
