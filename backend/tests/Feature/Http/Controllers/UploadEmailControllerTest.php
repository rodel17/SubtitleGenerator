<?php

use App\Enums\EmailStatus;
use App\Enums\UploadStatus;
use App\Jobs\SendSubtitlesEmail;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    Queue::fake();
});

it('queues the email and reports it as sending', function () {
    $upload = Upload::factory()->for($this->user)->ready()->create();

    $this->postJson("/api/uploads/{$upload->id}/email")
        ->assertAccepted()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.email_status', 'sending')
        ->assertJsonPath('data.email_error', null);

    expect($upload->fresh()->email_status)->toBe(EmailStatus::Sending);
    Queue::assertPushed(SendSubtitlesEmail::class, fn (SendSubtitlesEmail $job) => $job->upload->is($upload));
});

it('tries again after a failed email', function () {
    $upload = Upload::factory()->for($this->user)->ready()->create([
        'email_status' => EmailStatus::Failed,
        'email_error' => "We couldn't email your subtitles. Please download them instead.",
    ]);

    $this->postJson("/api/uploads/{$upload->id}/email")
        ->assertAccepted()
        ->assertJsonPath('data.email_status', 'sending')
        ->assertJsonPath('data.email_error', null);

    Queue::assertPushed(SendSubtitlesEmail::class);
});

it('sends only one email when asked twice', function () {
    $upload = Upload::factory()->for($this->user)->ready()->create();

    $this->postJson("/api/uploads/{$upload->id}/email")->assertAccepted();
    $this->postJson("/api/uploads/{$upload->id}/email")
        ->assertConflict()
        ->assertJsonPath('message', 'These subtitles are already being emailed.');

    Queue::assertPushedTimes(SendSubtitlesEmail::class, 1);
});

it('returns 409 until the subtitles are ready', function (UploadStatus $status) {
    $upload = Upload::factory()->for($this->user)->uploaded()->create(['status' => $status]);

    $this->postJson("/api/uploads/{$upload->id}/email")
        ->assertConflict()
        ->assertJsonPath('message', "These subtitles aren't ready yet.");

    Queue::assertNothingPushed();
})->with([
    'uploading' => UploadStatus::Uploading,
    'transcribing' => UploadStatus::Transcribing,
    'failed' => UploadStatus::Failed,
]);

it("returns 404 for another user's upload, so nobody can email someone else's subtitles", function () {
    $upload = Upload::factory()->ready()->create();

    $this->postJson("/api/uploads/{$upload->id}/email")->assertNotFound();

    Queue::assertNothingPushed();
});
