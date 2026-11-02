<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pm_checksheet_standards', function (Blueprint $table) {
            $table->string('selection_mode', 20)->nullable();
        });

        Schema::table('pm_execution_items', function (Blueprint $table) {
            $table->string('selection_mode_snapshot', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pm_execution_items', function (Blueprint $table) {
            $table->dropColumn('selection_mode_snapshot');
        });

        Schema::table('pm_checksheet_standards', function (Blueprint $table) {
            $table->dropColumn('selection_mode');
        });
    }
};
