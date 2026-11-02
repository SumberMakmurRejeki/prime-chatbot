<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_execution_items', function (Blueprint $table): void {
            $table->boolean('is_required_snapshot')->default(false)->after('unit_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('pm_execution_items', function (Blueprint $table): void {
            $table->dropColumn('is_required_snapshot');
        });
    }
};
