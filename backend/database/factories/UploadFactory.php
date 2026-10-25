<?php

namespace Database\Factories;

use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
            'user_id' => User::factory(),
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
     * An upload declared for exactly these bytes, split into $chunkSize pieces.
     */
    public function forContent(string $bytes, string $name = 'clip.wav', int $chunkSize = 20): static
    {
        return $this->state(fn (): array => [
            'original_name' => $name,
            'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            'mime_type' => null,
            'size' => strlen($bytes),
            'chunk_size' => $chunkSize,
            'total_chunks' => (int) ceil(strlen($bytes) / $chunkSize),
        ]);
    }

    /**
     * An upload whose chunks have all been received and assembled.
     */
    public function uploaded(): static
    {
        // The media file is named after the upload, as ChunkedUploadService::assemble() names it
        return $this->state(function (array $attributes): array {
            $id = $attributes['id'] ?? (string) Str::uuid7();

            return [
                'id' => $id,
                'status' => UploadStatus::Uploaded,
                'path' => "uploads/{$id}.{$attributes['extension']}",
                'completed_at' => now(),
            ];
        });
    }

    /**
     * An upload that has been transcribed; only its subtitle files are left, waiting to be downloaded.
     */
    public function ready(): static
    {
        return $this->uploaded()->state(fn (): array => [
            'status' => UploadStatus::Ready,
            'path' => null,
            'finished_at' => now(),
        ]);
    }

    /**
     * An upload whose processing stopped; its files are already deleted.
     */
    public function failed(): static
    {
        return $this->uploaded()->state(fn (): array => [
            'status' => UploadStatus::Failed,
            'error' => 'Transcription failed. Please try again.',
            'path' => null,
            'finished_at' => now(),
        ]);
    }
}
