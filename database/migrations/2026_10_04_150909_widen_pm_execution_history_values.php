<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pm_execution_history', function (Blueprint $table) {
            $table->longText('old_value')->nullable()->change();
            $table->longText('new_value')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $originalSqlMode = null;

        if ($mysql) {
            $originalSqlMode = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
            // Mode ketat menjaga nilai yang masuk setelah pemeriksaan agar rollback tidak memangkas histori.
            DB::statement('SET SESSION sql_mode = ?', [implode(',', array_unique([...array_filter(explode(',', $originalSqlMode)), 'STRICT_ALL_TABLES']))]);
        }

        try {
            // SQLite tidak memiliki batas TEXT MySQL; hanya MySQL yang perlu pemeriksaan byte.
            if ($mysql && DB::table('pm_execution_history')
                ->whereRaw('OCTET_LENGTH(old_value) > 65535 OR OCTET_LENGTH(new_value) > 65535')
                ->exists()) {
                throw new RuntimeException('Rollback PM history ditolak: nilai histori melebihi kapasitas TEXT.');
            }

            Schema::table('pm_execution_history', function (Blueprint $table) {
                $table->text('old_value')->nullable()->change();
                $table->text('new_value')->nullable()->change();
            });
        } finally {
            if ($mysql) {
                DB::statement('SET SESSION sql_mode = ?', [$originalSqlMode]);
            }
        }
    }
};
