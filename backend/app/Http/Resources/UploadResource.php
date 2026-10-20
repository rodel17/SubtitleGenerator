<?php

namespace App\Http\Resources;

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
            'size' => $this->size,
            'chunk_size' => $this->chunk_size,
            'total_chunks' => $this->total_chunks,
            'language' => $this->language,
            'subtitle_format' => $this->subtitle_format,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
