<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('status')->index();
        });

        DB::table('bookings')
            ->where('status', 'pending')
            ->whereNull('expires_at')
            ->orderBy('id')
            ->chunkById(100, function ($bookings): void {
                foreach ($bookings as $booking) {
                    DB::table('bookings')
                        ->where('id', $booking->id)
                        ->update([
                            'expires_at' => Carbon::parse($booking->created_at)->addMinutes(15),
                        ]);
                }
            });
    }
};
