<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lock_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('code');
            $table->dateTime('valid_from');
            $table->dateTime('valid_until');
            $table->enum('status', ['generated', 'sent', 'active', 'expired', 'revoked'])->default('generated');
            $table->timestamps();
        });
    }
};
