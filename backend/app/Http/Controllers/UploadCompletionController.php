<?php

namespace App\Http\Controllers;

use App\Http\Resources\UploadResource;
use App\Models\Upload;
use App\Services\ChunkedUploadService;
use Illuminate\Http\JsonResponse;

class UploadCompletionController extends Controller
{
    /**
     * Check the received chunks and queue their assembly. Returns 202: poll the
     * upload to follow assembly and transcription.
     */
    public function store(Upload $upload, ChunkedUploadService $uploads): JsonResponse
    {
        abort_unless($upload->isUploading(), 409, 'This upload is already complete.');

        return UploadResource::make($uploads->complete($upload))->response()->setStatusCode(202);
    }
}
