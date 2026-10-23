<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUploadRequest;
use App\Http\Resources\UploadResource;
use App\Models\Upload;
use App\Services\ChunkedUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class UploadController extends Controller
{
    /**
     * List the signed-in user's most recent uploads.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return UploadResource::collection($request->user()->uploads()->latest()->limit(20)->get());
    }

    /**
     * Start a chunked upload. The response tells the client how to split the file.
     */
    public function store(StoreUploadRequest $request, ChunkedUploadService $uploads): JsonResponse
    {
        $upload = $uploads->start($request->user(), $request->validated());

        return UploadResource::make($upload)->response()->setStatusCode(201);
    }

    /**
     * Show a single upload.
     */
    public function show(Upload $upload): UploadResource
    {
        return UploadResource::make($upload);
    }

    /**
     * Cancel or delete an upload, along with its files.
     */
    public function destroy(Upload $upload, ChunkedUploadService $uploads): Response
    {
        $uploads->deleteFiles($upload);
        $upload->delete();

        return response()->noContent();
    }
}
