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
        Schema::create('ai_provider_configurations', function (Blueprint $table): void {
            $table->id();
            $table->string('display_name', 120);
            $table->string('purpose', 32);
            $table->unsignedBigInteger('active_revision_id')->nullable();
            $table->unsignedBigInteger('previous_known_good_revision_id')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_provider_configuration_revisions', function (Blueprint $table): void {
            $table->id();
            // Nama constraint eksplisit: nama default Laravel melebihi batas 64 karakter MySQL.
            $table->foreignId('configuration_id');
            $table->unsignedInteger('revision_number');
            $table->string('driver', 64);
            $table->string('model_id', 255);
            $table->string('base_url', 2048)->nullable();
            $table->text('credential')->nullable();
            $table->json('generation_parameters')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('test_state', 32)->default('untested');
            $table->timestamps();
            $table->unique(['configuration_id', 'revision_number'], 'ai_provider_revision_number_unique');
            $table->unique(['configuration_id', 'id'], 'ai_provider_revision_id_unique');
            $table->foreign('configuration_id', 'ai_provider_revision_configuration_fk')
                ->references('id')->on('ai_provider_configurations')->cascadeOnDelete();

        });

        Schema::table('ai_provider_configurations', function (Blueprint $table): void {
            $table->foreign(['id', 'active_revision_id'], 'ai_provider_active_revision_fk')
                ->references(['configuration_id', 'id'])->on('ai_provider_configuration_revisions');
            $table->foreign(['id', 'previous_known_good_revision_id'], 'ai_provider_previous_revision_fk')
                ->references(['configuration_id', 'id'])->on('ai_provider_configuration_revisions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_provider_configurations', function (Blueprint $table): void {
            $table->dropForeign('ai_provider_active_revision_fk');
            $table->dropForeign('ai_provider_previous_revision_fk');
        });
        Schema::dropIfExists('ai_provider_configuration_revisions');
        Schema::dropIfExists('ai_provider_configurations');
    }
};
