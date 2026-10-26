<?php

use App\Enums\EmailStatus;
use App\Jobs\SendSubtitlesEmail;
use App\Mail\SubtitlesReady;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Resend\Exceptions\ErrorException;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function () {
    Storage::fake('local');
});

function emailingUpload(array $attributes = []): Upload
{
    $upload = Upload::factory()
        ->for(User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']))
        ->ready()
        ->create(['original_name' => 'Team Interview.mp4', 'subtitle_format' => 'both', 'email_status' => EmailStatus::Sending, ...$attributes]);
    Storage::disk('local')->put($upload->subtitlePath('srt'), "1\n00:00:01,000 --> 00:00:02,000\nHi\n");
    Storage::disk('local')->put($upload->subtitlePath('vtt'), "WEBVTT\n");

    return $upload;
}

/**
 * What Laravel's Resend transport throws when the API refuses to send.
 */
function resendFailure(string $type, int $status = 429): TransportException
{
    $error = new ErrorException(['statusCode' => $status, 'name' => $type, 'message' => "Resend said: {$type}"]);

    return new TransportException("Request to Resend API failed. Reason: {$error->getMessage()}.", 0, $error);
}

it("sends the subtitles to the owner's own address", function () {
    Mail::fake();
    $upload = emailingUpload();

    SendSubtitlesEmail::dispatch($upload);

    Mail::assertSent(SubtitlesReady::class, function (SubtitlesReady $mail) {
        return $mail->hasTo('ada@example.com')
            && count($mail->to) === 1
            && $mail->hasSubject('Your subtitles for Team Interview.mp4');
    });
});

it('deletes the upload and its subtitles once the email is sent', function () {
    Mail::fake();
    $upload = emailingUpload();

    SendSubtitlesEmail::dispatch($upload);

    $this->assertModelMissing($upload);
    Storage::disk('local')->assertMissing([$upload->subtitlePath('srt'), $upload->subtitlePath('vtt')]);
});

it('keeps the subtitles for download and explains when the email fails', function () {
    $upload = emailingUpload();
    Mail::shouldReceive('to->send')->andThrow(resendFailure('internal_server_error', 500));

    expect(fn () => SendSubtitlesEmail::dispatch($upload))->toThrow(TransportException::class);

    $upload->refresh();
    expect($upload->email_status)->toBe(EmailStatus::Failed);
    expect($upload->email_error)->toBe("We couldn't email your subtitles. Please download them instead.");
    Storage::disk('local')->assertExists($upload->subtitlePath('srt'));
});

it('gives up at once and says email is unavailable when the Resend quota is used up', function (string $type) {
    $upload = emailingUpload();
    Mail::shouldReceive('to->send')->once()->andThrow(resendFailure($type));

    SendSubtitlesEmail::dispatch($upload);

    $upload->refresh();
    expect($upload->email_status)->toBe(EmailStatus::Failed);
    expect($upload->email_error)->toBe("Email isn't available right now. Please download your subtitles instead.");
    Storage::disk('local')->assertExists($upload->subtitlePath('srt'));
})->with(['daily_quota_exceeded', 'monthly_quota_exceeded']);

it('retries a temporary failure a few times with a pause', function () {
    $job = new SendSubtitlesEmail(emailingUpload());

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([30, 120]);
});

it('attaches each subtitle file under the media name', function () {
    $mail = new SubtitlesReady(emailingUpload());

    $mail->assertHasAttachedData("1\n00:00:01,000 --> 00:00:02,000\nHi\n", 'Team Interview.srt', ['mime' => 'application/x-subrip']);
    $mail->assertHasAttachedData("WEBVTT\n", 'Team Interview.vtt', ['mime' => 'text/vtt']);
    expect($mail->attachments())->toHaveCount(2);
});

it('attaches only the requested format', function () {
    $mail = new SubtitlesReady(emailingUpload(['subtitle_format' => 'vtt']));

    expect($mail->attachments())->toHaveCount(1);
    $mail->assertHasAttachedData("WEBVTT\n", 'Team Interview.vtt', ['mime' => 'text/vtt']);
});

it('comes from SubGen, with the SubGen header and footer and no Laravel branding', function () {
    config(['app.name' => 'Laravel', 'mail.from.name' => 'Laravel', 'app.frontend_url' => 'https://app.subgen.test']);
    $mail = new SubtitlesReady(emailingUpload());

    $mail->assertFrom(config('mail.from.address'), 'SubGen');
    $mail->assertSeeInOrderInHtml(['https://app.subgen.test', 'CC', 'SubGen', 'Team Interview.mp4', '© '.date('Y').' SubGen.']);
    $mail->assertDontSeeInHtml('Laravel');
    $mail->assertSeeInText('SubGen');
    $mail->assertDontSeeInText('Laravel');
});

it('tells the user the subtitles are attached and deleted from the servers', function () {
    $mail = new SubtitlesReady(emailingUpload());

    $mail->assertSeeInHtml('Team Interview.mp4');
    $mail->assertSeeInText('deleted from our servers');
});
