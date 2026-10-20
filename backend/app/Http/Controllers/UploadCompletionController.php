<?php

namespace App\Http\Controllers;

use App\Http\Resources\UploadResource;
use App\Models\Upload;
use App\Services\ChunkedUploadService;

class UploadCompletionController extends Controller
{
    /**
     * Assemble the received chunks into the final media file.
     */
    public function store(Upload $upload, ChunkedUploadService $uploads): UploadResource
    {
        abort_unless($upload->isUploading(), 409, 'This upload is already complete.');

        return UploadResource::make($uploads->complete($upload));
    }
}
