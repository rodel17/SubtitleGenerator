<?php

use App\Http\Controllers\SubtitleDownloadController;
use App\Http\Controllers\UploadChunkController;
use App\Http\Controllers\UploadCompletionController;
use App\Http\Controllers\UploadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/test', function () {
    return response()->json(['message' => 'API is working']);
});

Route::apiResource('uploads', UploadController::class)->except(['update']);
Route::get('/uploads/{upload}/chunks', [UploadChunkController::class, 'index'])->name('uploads.chunks.index');
Route::post('/uploads/{upload}/chunks', [UploadChunkController::class, 'store'])->name('uploads.chunks.store');
Route::post('/uploads/{upload}/completion', [UploadCompletionController::class, 'store'])->name('uploads.completion.store');
Route::get('/uploads/{upload}/subtitles/{format}', SubtitleDownloadController::class)
    ->whereIn('format', ['srt', 'vtt'])
    ->name('uploads.subtitles.show');
