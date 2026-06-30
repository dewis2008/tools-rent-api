<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('provider_refund_id')->nullable()->unique()->after('provider_payment_id');
            $table
                ->enum('status', ['pending', 'paid', 'failed', 'refund_pending', 'refund_failed', 'refunded'])
                ->default('pending')
                ->change();
        });
    }
};
