<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vendor_profiles', 'deleted_at')) {
            Schema::table('vendor_profiles', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (! Schema::hasColumn('tools', 'deleted_at')) {
            Schema::table('tools', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['tool_id']);
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['vendor_id']);

            $table->foreign('tool_id')->references('id')->on('tools')->restrictOnDelete();
            $table->foreign('customer_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('vendor_id')->references('id')->on('vendor_profiles')->restrictOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropForeign(['customer_id']);

            $table->foreign('booking_id')->references('id')->on('bookings')->restrictOnDelete();
            $table->foreign('customer_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('lock_codes', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);

            $table->foreign('booking_id')->references('id')->on('bookings')->restrictOnDelete();
        });
    }
};
