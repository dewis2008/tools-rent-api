<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lock_codes', function (Blueprint $table) {
            $table->text('code')->change();
        });

        DB::table('lock_codes')
            ->select(['id', 'code'])
            ->orderBy('id')
            ->chunk(100, function ($lockCodes): void {
                foreach ($lockCodes as $lockCode) {
                    if ($this->isEncrypted($lockCode->code)) {
                        continue;
                    }

                    DB::table('lock_codes')
                        ->where('id', $lockCode->id)
                        ->update(['code' => Crypt::encryptString($lockCode->code)]);
                }
            });
    }

    private function isEncrypted(string $code): bool
    {
        try {
            Crypt::decryptString($code);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
