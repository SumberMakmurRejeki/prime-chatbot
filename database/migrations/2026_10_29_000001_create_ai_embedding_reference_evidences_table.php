<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_embedding_reference_evidences', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_fingerprint', 64)->unique('ai_embedding_reference_fingerprint_unique');
            $table->string('probe_profile', 64);
            $table->unsignedSmallInteger('probe_profile_version');
            $table->string('provenance_fingerprint', 64);
            $table->string('driver', 64);
            $table->string('endpoint', 2048)->nullable();
            $table->string('model_id', 255);
            $table->unsignedInteger('dimension');
            $table->string('distance_metric', 32);
            $table->longText('generation_parameters')->nullable();
            $table->unsignedBigInteger('source_configuration_id')->nullable();
            $table->unsignedBigInteger('source_revision_id')->nullable();
            $table->longText('probe_outputs');
            $table->string('payload_checksum', 64);
            $table->string('evidence_checksum', 64);
            $table->timestamp('generated_at', 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_embedding_reference_evidences');
    }
};
