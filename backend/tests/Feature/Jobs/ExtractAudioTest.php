<?php

use App\Enums\UploadStatus;
use App\Exceptions\TranscriptionException;
use App\Jobs\ExtractAudio;
use App\Models\Upload;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->upload = Upload::factory()->uploaded()->create(['extension' => 'mp4']);
    Storage::disk('local')->put($this->upload->path, 'media');
});

it('converts the media to 16 kHz mono WAV pieces of the configured length', function () {
    config(['transcription.piece_seconds' => 120]);
    Process::fake([
        '*ffprobe*' => Process::result('250.5'),
        '*ffmpeg*' => function () {
            Storage::disk('local')->put("audio/{$this->upload->id}/0000.wav", wavBytes());

            return Process::result();
        },
    ]);

    ExtractAudio::dispatch($this->upload);

    Process::assertRan(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), 'ffmpeg')
        && in_array('16000', $process->command, true)
        && in_array('pcm_s16le', $process->command, true)
        && $process->command[array_search('-segment_time', $process->command, true) + 1] === '120'
        && in_array(Storage::disk('local')->path($this->upload->path), $process->command, true));
    expect($this->upload->fresh()->status)->toBe(UploadStatus::Transcribing);
});

it('marks the upload failed when ffmpeg finds no audio', function () {
    Process::fake([
        '*ffprobe*' => Process::result('10'),
        '*ffmpeg*' => Process::result(errorOutput: 'Output file does not contain any stream', exitCode: 1),
    ]);

    expect(fn () => ExtractAudio::dispatch($this->upload))->toThrow(TranscriptionException::class);

    $upload = $this->upload->fresh();
    expect($upload->status)->toBe(UploadStatus::Failed);
    expect($upload->error)->toBe('No audio track could be read from this file.');
    Storage::disk('local')->assertMissing("audio/{$this->upload->id}");
});

it('marks the upload failed when ffmpeg succeeds but writes no audio', function () {
    Process::fake(['*ffprobe*' => Process::result('10'), '*ffmpeg*' => Process::result()]);

    expect(fn () => ExtractAudio::dispatch($this->upload))->toThrow(TranscriptionException::class);

    expect($this->upload->fresh()->error)->toBe('No audio track could be read from this file.');
});

it('replaces audio left over from an earlier attempt', function () {
    Storage::disk('local')->put("audio/{$this->upload->id}/0007.wav", 'stale');
    Process::fake([
        '*ffprobe*' => Process::result('10'),
        '*ffmpeg*' => function () {
            Storage::disk('local')->put("audio/{$this->upload->id}/0000.wav", wavBytes());

            return Process::result();
        },
    ]);

    ExtractAudio::dispatch($this->upload);

    expect(Storage::disk('local')->files("audio/{$this->upload->id}"))->toBe(["audio/{$this->upload->id}/0000.wav"]);
});
