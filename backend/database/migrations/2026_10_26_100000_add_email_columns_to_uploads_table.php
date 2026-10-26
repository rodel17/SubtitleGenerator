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
        Schema::table('uploads', function (Blueprint $table) {
            // Chosen before uploading: email the subtitles as soon as they're ready
            $table->boolean('email_when_ready')->default(false)->after('subtitle_format');
            $table->string('email_status', 20)->nullable()->after('error');
            $table->string('email_error', 500)->nullable()->after('email_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropColumn(['email_when_ready', 'email_status', 'email_error']);
        });
    }
};
