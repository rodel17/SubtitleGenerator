<?php

namespace App\Http\Resources;

use App\Enums\UploadStatus;
use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Upload
 */
class UploadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'extension' => $this->extension,
            'mime_type' => $this->mime_type,
            'last_modified' => $this->last_modified,
            'size' => $this->size,
            'chunk_size' => $this->chunk_size,
            'total_chunks' => $this->total_chunks,
            'language' => $this->language,
            'subtitle_format' => $this->subtitle_format,
            'email_when_ready' => $this->email_when_ready,
            'status' => $this->status,
            'progress' => $this->progress(),
            'error' => $this->error,
            'email_status' => $this->email_status,
            'email_error' => $this->email_error,
            'download_url' => $this->when(
                $this->status === UploadStatus::Ready,
                fn (): string => route('uploads.subtitles.show', $this->id),
            ),
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,
            'expires_at' => $this->expiresAt(),
        ];
    }
}
