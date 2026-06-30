<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_tool_image_file_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('image_path')->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }
};
