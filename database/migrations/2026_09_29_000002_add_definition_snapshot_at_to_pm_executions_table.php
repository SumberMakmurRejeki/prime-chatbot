<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda bahwa definisi (part/standard/opsi action) sudah dibekukan sebagai
     * pm_execution_items di dalam transaksi Mulai PM. Marker null berarti eksekusi
     * legacy yang definisinya direkam sebelum fitur ini (lihat command capture).
     *
     * Marker sengaja nullable dan tanpa index: query-nya selalu per-execution,
     * bukan pencarian lintas baris.
     */
    public function up(): void
    {
        Schema::table('pm_executions', function (Blueprint $table) {
            $table->timestamp('definition_snapshot_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pm_executions', function (Blueprint $table) {
            $table->dropColumn('definition_snapshot_at');
        });
    }
};
