<?php

namespace App\Http\Controllers;

use App\Enums\EmailStatus;
use App\Enums\UploadStatus;
use App\Http\Resources\UploadResource;
use App\Jobs\SendSubtitlesEmail;
use App\Models\Upload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class UploadEmailController extends Controller
{
    /**
     * Email the subtitles to the signed-in user (their own address only). Returns
     * 202: the upload is deleted once the email is sent, so polling it then gives 404.
     */
    public function store(Upload $upload): JsonResponse
    {
        abort_unless($upload->status === UploadStatus::Ready, 409, "These subtitles aren't ready yet.");

        // Claim it atomically so a double click can't send two emails
        $claimed = Upload::query()
            ->whereKey($upload->id)
            ->where(fn (Builder $query) => $query->whereNull('email_status')->orWhere('email_status', EmailStatus::Failed))
            ->update(['email_status' => EmailStatus::Sending, 'email_error' => null]);

        abort_if($claimed === 0, 409, 'These subtitles are already being emailed.');

        SendSubtitlesEmail::dispatch($upload->refresh());

        return UploadResource::make($upload)->response()->setStatusCode(202);
    }
}
