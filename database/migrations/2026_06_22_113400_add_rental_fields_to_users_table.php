<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('password');
            $table->enum('role', ['admin', 'vendor', 'customer'])->after('phone');
            $table->enum('status', ['active', 'blocked', 'pending'])->default('pending')->after('role');
        });
    }
};
