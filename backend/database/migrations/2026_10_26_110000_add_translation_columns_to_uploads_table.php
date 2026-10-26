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
            // Language to translate the subtitles into; null keeps the spoken language
            $table->string('translate_to', 10)->nullable()->after('language');
            // "local" (on our server) for now; "azure" comes with the Enhanced option
            $table->string('translation_engine', 10)->nullable()->after('translate_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropColumn(['translate_to', 'translation_engine']);
        });
    }
};
