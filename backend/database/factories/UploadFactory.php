<?php

namespace Database\Factories;

use App\Enums\UploadStatus;
use App\Models\Upload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Upload>
 */
class UploadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $chunkSize = 5 * 1024 * 1024;
        $size = fake()->numberBetween(1, 20) * $chunkSize;

        return [
            'original_name' => fake()->slug(2).'.mp4',
            'extension' => 'mp4',
            'mime_type' => 'video/mp4',
            'size' => $size,
            'chunk_size' => $chunkSize,
            'total_chunks' => (int) ceil($size / $chunkSize),
            'language' => 'auto',
            'subtitle_format' => 'srt',
            'status' => UploadStatus::Uploading,
        ];
    }

    /**
     * An upload whose chunks have all been received and assembled.
     */
    public function uploaded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UploadStatus::Uploaded,
            'path' => 'uploads/'.fake()->uuid().'.'.$attributes['extension'],
            'completed_at' => now(),
        ]);
    }
}
