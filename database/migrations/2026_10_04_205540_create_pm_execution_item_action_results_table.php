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
        Schema::create('pm_execution_item_action_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pm_execution_item_id');
            // Snapshot legacy berupa JSON tanpa batas label; jangan perkecil hasil terpilih menjadi VARCHAR(50).
            $table->longText('selected_label_snapshot');
            $table->char('selected_label_identity_hash', 64);
            $table->unsignedInteger('option_position_snapshot');
            $table->timestamps();
            $table->unique(['pm_execution_item_id', 'option_position_snapshot'], 'pm_exec_action_result_position_unique');
            $table->unique(['pm_execution_item_id', 'selected_label_identity_hash'], 'pm_exec_action_result_label_unique');
            $table->foreign('pm_execution_item_id', 'pm_exec_action_result_item_foreign')
                ->references('id')->on('pm_execution_items')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pm_execution_item_action_results');
    }
};
