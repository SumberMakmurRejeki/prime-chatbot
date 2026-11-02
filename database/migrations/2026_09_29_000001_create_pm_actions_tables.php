<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master Action adalah entitas reusable khusus PM: satu action dapat dipakai
     * banyak standard action sebagai subset berurutan. Tabel pivot menyimpan urutan
     * (position) karena urutan tampil di wizard dan snapshot Executor mengikuti urutan itu.
     */
    public function up(): void
    {
        Schema::create('pm_actions', function (Blueprint $table) {
            $table->id();
            // Label kanonik (uppercase UTF-8) yang sudah dinormalisasi service; unique
            // menjadi backstop concurrent write, bukan hanya validasi request.
            $table->string('action_label', 50)->unique('pm_actions_label_unique');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('pm_checksheet_standard_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pm_checksheet_standard_id')->index();
            $table->foreignId('pm_action_id')->index();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['pm_checksheet_standard_id', 'pm_action_id'], 'pcsa_standard_action_unique');
            $table->unique(['pm_checksheet_standard_id', 'position'], 'pcsa_standard_position_unique');
            $table->foreign('pm_checksheet_standard_id', 'pcsa_pm_checksheet_standard_id_foreign')
                ->references('id')
                ->on('pm_checksheet_standards')
                ->cascadeOnDelete();
            // Restrict: Master Action yang masih dirujuk tidak boleh hilang, sehingga
            // Referenced action dinonaktifkan (bukan dihapus) dan histori tetap konsisten.
            $table->foreign('pm_action_id', 'pcsa_pm_action_id_foreign')
                ->references('id')
                ->on('pm_actions')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pm_checksheet_standard_actions');
        Schema::dropIfExists('pm_actions');
    }
};
