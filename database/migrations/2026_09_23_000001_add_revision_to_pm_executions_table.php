<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_executions', function (Blueprint $table): void {
            // Freshness token tunggal untuk seluruh execution: penulis result wajib
            // membawa expected_revision yang cocok dengan row execution yang terkunci.
            // Tidak diberi index karena perbandingan selalu terjadi pada primary-key
            // row yang sudah di-FOR UPDATE; index hanya menambah write cost.
            $table->unsignedBigInteger('revision')->default(0)->after('review_note');
        });
    }

    public function down(): void
    {
        // Rollback menghapus state konkurensi yang sudah pernah maju; setelah deploy
        // production rollback harus berupa forward repair, bukan migration rollback.
        Schema::table('pm_executions', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
    }
};
