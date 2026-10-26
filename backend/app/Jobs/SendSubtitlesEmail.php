<?php

namespace App\Jobs;

use App\Enums\EmailStatus;
use App\Mail\SubtitlesReady;
use App\Models\Upload;
use App\Services\ChunkedUploadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Resend\Exceptions\ErrorException;
use Throwable;

/**
 * Email a ready upload's subtitles to its owner (only ever their own address),
 * then delete the upload: once sent, nothing is kept. When sending fails the
 * subtitles stay, so the user can download them instead.
 */
class SendSubtitlesEmail implements ShouldQueue
{
    use Queueable;

    /**
     * Retries cover a short outage at Resend; a used-up quota is not retried.
     */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public int $timeout = 45;

    /**
     * The upload was deleted (expired) before the job ran.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Upload $upload) {}

    public function handle(ChunkedUploadService $uploads): void
    {
        try {
            Mail::to($this->upload->user)->send(new SubtitlesReady($this->upload));
        } catch (Throwable $exception) {
            if (self::isQuotaExceeded($exception)) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }

        $uploads->deleteFiles($this->upload);
        $this->upload->delete();
    }

    public function failed(?Throwable $exception): void
    {
        $this->upload->update([
            'email_status' => EmailStatus::Failed,
            'email_error' => $exception !== null && self::isQuotaExceeded($exception)
                ? "Email isn't available right now. Please download your subtitles instead."
                : "We couldn't email your subtitles. Please download them instead.",
        ]);
    }

    /**
     * Resend's free plan allows 100 emails a day and 3,000 a month; retrying won't help until it resets.
     */
    private static function isQuotaExceeded(Throwable $exception): bool
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ErrorException && str_ends_with($cause->getErrorType(), '_quota_exceeded')) {
                return true;
            }
        }

        return false;
    }
}
