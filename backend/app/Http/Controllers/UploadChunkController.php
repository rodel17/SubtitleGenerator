<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUploadChunkRequest;
use App\Models\Upload;
use App\Services\ChunkedUploadService;
use Illuminate\Http\Response;

class UploadChunkController extends Controller
{
    /**
     * Receive one chunk of an in-progress upload.
     */
    public function store(StoreUploadChunkRequest $request, Upload $upload, ChunkedUploadService $uploads): Response
    {
        abort_unless($upload->isUploading(), 409, 'This upload is already complete.');

        $uploads->storeChunk($upload, $request->integer('index'), $request->file('chunk'));

        return response()->noContent();
    }
}
