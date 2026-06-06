<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('url', 2048)->nullable()->after('image_path');
            $table->string('preview_title')->nullable()->after('url');
            $table->string('preview_image', 2048)->nullable()->after('preview_title');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['url', 'preview_title', 'preview_image']);
        });
    }
};
