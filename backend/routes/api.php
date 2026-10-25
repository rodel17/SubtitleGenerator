<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\SubtitleDownloadController;
use App\Http\Controllers\UploadChunkController;
use App\Http\Controllers\UploadCompletionController;
use App\Http\Controllers\UploadController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/test', function () {
    return response()->json(['message' => 'API is working']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return UserResource::make($request->user());
    });
    Route::post('/logout', LogoutController::class)->name('logout');

    Route::apiResource('uploads', UploadController::class)
        ->except(['update'])
        ->middlewareFor('store', 'throttle:uploads')
        ->middlewareFor('show', 'can:view,upload')
        ->middlewareFor('destroy', 'can:delete,upload');
    Route::get('/uploads/{upload}/chunks', [UploadChunkController::class, 'index'])
        ->can('view', 'upload')
        ->name('uploads.chunks.index');
    Route::post('/uploads/{upload}/chunks', [UploadChunkController::class, 'store'])
        ->can('update', 'upload')
        ->name('uploads.chunks.store');
    Route::post('/uploads/{upload}/completion', [UploadCompletionController::class, 'store'])
        ->can('update', 'upload')
        ->name('uploads.completion.store');
    Route::get('/uploads/{upload}/subtitles', SubtitleDownloadController::class)
        ->can('view', 'upload')
        ->name('uploads.subtitles.show');
});
