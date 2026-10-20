<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('original_name');
            $table->string('extension', 10);
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->string('language', 10)->nullable();
            $table->string('subtitle_format', 10);
            $table->string('status', 20)->default('uploading');
            $table->string('path')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
