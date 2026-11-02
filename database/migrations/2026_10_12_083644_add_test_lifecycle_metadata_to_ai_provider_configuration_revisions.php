<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metadata siklus uji AI-1B2. AI-1B1 migration tidak diubah; kolom test_state
     * lama tetap dipakai dan hanya dilengkapi bukti attempt agar hasil provider
     * yang terlambat tidak bisa menimpa attempt yang lebih baru.
     */
    public function up(): void
    {
        Schema::table('ai_provider_configuration_revisions', function (Blueprint $table): void {
            $table->string('test_attempt_id', 36)->nullable()->after('test_state');
            $table->timestamp('test_started_at')->nullable()->after('test_attempt_id');
            $table->timestamp('tested_at', 3)->nullable()->after('test_started_at');
            $table->string('test_failure_category', 64)->nullable()->after('tested_at');
            $table->unsignedInteger('tested_embedding_dimensions')->nullable()->after('test_failure_category');
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_configuration_revisions', function (Blueprint $table): void {
            $table->dropColumn([
                'test_attempt_id',
                'test_started_at',
                'tested_at',
                'test_failure_category',
                'tested_embedding_dimensions',
            ]);
        });
    }
};
