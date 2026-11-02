<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

return new class extends AiMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::create($conversationsTable, function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('participant_type')->default(User::class);
            $table->unsignedBigInteger('participant_id');
            $table->string('title', 100);
            $table->timestamps();

            $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
            $table->foreign('participant_id', 'agent_conversations_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create($messagesTable, function (Blueprint $table) use ($conversationsTable) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36);
            $table->foreign('conversation_id', 'agent_messages_conversation_fk')
                ->references('id')->on($conversationsTable)->cascadeOnDelete();
            $table->string('participant_type')->default(User::class);
            $table->unsignedBigInteger('participant_id');
            $table->string('agent');
            $table->string('role', 25);
            $table->text('content');
            $table->text('attachments');
            $table->longText('steps');
            $table->text('usage');
            $table->text('meta');
            $table->string('status', 25);
            $table->timestamps();

            $table->index(['conversation_id', 'id'], 'agent_messages_conversation_order_index');
            $table->index(['participant_type', 'participant_id', 'agent'], 'participant_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('ai.conversations.tables.messages', 'agent_conversation_messages'));
        Schema::dropIfExists(config('ai.conversations.tables.conversations', 'agent_conversations'));
    }
};
