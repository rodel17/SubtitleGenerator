<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUploadChunkRequest;
use App\Models\Upload;
use App\Services\ChunkedUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class UploadChunkController extends Controller
{
    /**
     * Report which chunks the server already has, so an interrupted upload can resume.
     */
    public function index(Upload $upload, ChunkedUploadService $uploads): JsonResponse
    {
        abort_unless($upload->isUploading(), 409, 'This upload is already complete.');

        return response()->json(['data' => [
            'chunk_size' => $upload->chunk_size,
            'total_chunks' => $upload->total_chunks,
            'received' => $uploads->receivedChunkIndexes($upload),
        ]]);
    }

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
