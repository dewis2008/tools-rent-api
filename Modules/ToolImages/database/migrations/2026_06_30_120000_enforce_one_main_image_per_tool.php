<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const IndexName = 'tool_images_one_main_per_tool';

    public function up(): void
    {
        $this->clearDuplicateMainImages();

        match (DB::connection()->getDriverName()) {
            'sqlite' => DB::statement(
                'CREATE UNIQUE INDEX '.self::IndexName.' ON tool_images (tool_id) WHERE is_main = 1',
            ),
            'pgsql' => DB::statement(
                'CREATE UNIQUE INDEX '.self::IndexName.' ON tool_images (tool_id) WHERE is_main = true',
            ),
            'mysql', 'mariadb' => $this->addMySqlConstraint(),
            'sqlsrv' => DB::statement(
                'CREATE UNIQUE INDEX '.self::IndexName.' ON tool_images (tool_id) WHERE is_main = 1',
            ),
            default => throw new RuntimeException('The database driver does not support the tool image main constraint.'),
        };
    }

    private function clearDuplicateMainImages(): void
    {
        DB::table('tool_images')
            ->where('is_main', true)
            ->select('tool_id')
            ->groupBy('tool_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('tool_id')
            ->each(function (int $toolId): void {
                $mainImageId = DB::table('tool_images')
                    ->where('tool_id', $toolId)
                    ->where('is_main', true)
                    ->max('id');

                DB::table('tool_images')
                    ->where('tool_id', $toolId)
                    ->where('is_main', true)
                    ->where('id', '!=', $mainImageId)
                    ->update(['is_main' => false]);
            });
    }

    private function addMySqlConstraint(): void
    {
        Schema::table('tool_images', function (Blueprint $table): void {
            $table
                ->unsignedBigInteger('main_tool_id')
                ->storedAs('CASE WHEN is_main = 1 THEN tool_id ELSE NULL END');
            $table->unique('main_tool_id', self::IndexName);
        });
    }
};
