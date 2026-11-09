<?php

namespace App\Services\AI;

use App\Exceptions\ConversationLockUnavailable;
use App\Exceptions\ConversationRequestConflict;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

class AiConversationService
{
    private const CONVERSATION_PAGE_SIZE = 10;

    private const HISTORY_PAGE_SIZE = 25;

    public function __construct(private readonly GroundedAnswerService $answers) {}

    /** @return MorphMany<Conversation, User> */
    private function ownedConversations(User $user): MorphMany
    {
        return $user->conversations();
    }

    /** @return LengthAwarePaginator<int, array{id: string, title: string, updated_at: ?string}> */
    public function listFor(User $user): LengthAwarePaginator
    {
        $this->assertActiveAdmin($user);

        return $this->ownedConversations($user)
            ->select(['id', 'participant_type', 'participant_id', 'title', 'updated_at'])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(self::CONVERSATION_PAGE_SIZE)
            ->through(fn (Conversation $conversation): array => [
                'id' => (string) $conversation->getKey(),
                'title' => $conversation->title,
                'updated_at' => $conversation->updated_at?->toIso8601String(),
            ])->withQueryString();
    }

    public function createFor(User $user): string
    {
        $this->assertActiveAdmin($user);

        return (string) $this->ownedConversations($user)->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->getKey(),
            'title' => 'Percakapan baru',
        ])->getKey();
    }

    /** @return array{conversation: array{id: string, title: string, created_at: ?string}, messages: CursorPaginator<int, array{id: string, role: string, content: ?string, citations: array, created_at: ?string}>, displayMessages: array<int, array{role: string, content: ?string, citations: array, created_at: ?string}>} */
    public function showFor(User $user, string $conversationId, ?string $cursor): array
    {
        $conversation = $this->resolveOwned($user, $conversationId);
        $messages = $conversation->messages()->orderByDesc('id')->cursorPaginate(
            self::HISTORY_PAGE_SIZE,
            ['id', 'conversation_id', 'role', 'content', 'meta', 'created_at'],
            'cursor',
            $cursor,
        );
        $messages->setCollection($messages->getCollection()->map(fn (ConversationMessage $message): array => [
            'id' => (string) $message->getKey(),
            'role' => $message->role,
            'content' => $message->content,
            'citations' => $message->role === 'assistant' ? (array) data_get($message->meta, 'citations', []) : [],
            'created_at' => $message->created_at?->toIso8601String(),
        ])->values());

        return [
            'conversation' => ['id' => (string) $conversation->getKey(), 'title' => $conversation->title, 'created_at' => $conversation->created_at?->toIso8601String()],
            'messages' => $messages,
            'displayMessages' => $messages->getCollection()->reverse()->values()->all(),
        ];
    }

    public function resolveOwned(User $user, string $conversationId): Conversation
    {
        $this->assertActiveAdmin($user);

        return $this->ownedConversations($user)->whereKey($conversationId)->firstOrFail();
    }

    public function answerAndAppend(User $user, string $conversationId, string $content, string $requestId): void
    {
        $this->resolveOwned($user, $conversationId);
        $lock = $this->openConversationLock($conversationId);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new ConversationRequestConflict('Pesan lain sedang diproses. Coba lagi.', 'request_in_progress');
        }

        try {
            $conversation = $this->resolveOwned($user, $conversationId);
            $existing = $conversation->messages()
                ->where('role', 'user')
                ->where('meta->request_id', $requestId)
                ->first();
            if ($existing) {
                if ($existing->content !== $content) {
                    throw new ConversationRequestConflict('ID permintaan ini sudah digunakan untuk pesan berbeda.', 'request_id_conflict');
                }

                return;
            }

            $result = $this->answers->answer($content);
            $timestamp = now();

            DB::transaction(function () use ($user, $conversationId, $content, $requestId, $result, $timestamp): void {
                $conversation = $this->resolveOwned($user, $conversationId);
                $firstMessage = ! $conversation->messages()->exists();
                $conversation->messages()->create([
                    'id' => (string) Str::uuid7(), 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->getKey(),
                    'agent' => 'prime-chat-rag', 'role' => 'user', 'content' => $content, 'attachments' => [], 'steps' => [], 'usage' => [],
                    'meta' => ['request_id' => $requestId], 'status' => MessageStatus::Completed, 'created_at' => $timestamp, 'updated_at' => $timestamp,
                ]);
                $conversation->messages()->create([
                    'id' => (string) Str::uuid7(), 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->getKey(),
                    'agent' => 'prime-chat-rag', 'role' => 'assistant', 'content' => $result['answer'], 'attachments' => [], 'steps' => [], 'usage' => [],
                    'meta' => ['citations' => $result['citations'], 'request_id' => $requestId], 'status' => MessageStatus::Completed, 'created_at' => $timestamp, 'updated_at' => $timestamp,
                ]);

                if ($firstMessage) {
                    $conversation->forceFill(['title' => Str::limit($content, 100, preserveWords: true)])->save();
                } else {
                    $conversation->touch();
                }
            });
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function deleteFor(User $user, string $conversationId): void
    {
        $this->resolveOwned($user, $conversationId);
        $lock = $this->openConversationLock($conversationId);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new ConversationRequestConflict('Percakapan sedang diproses. Coba lagi.', 'request_in_progress');
        }

        try {
            $this->resolveOwned($user, $conversationId)->delete();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Local file lock coordinates concurrent workers without DB-backed or array-cache coupling.
     *
     * @return resource
     */
    private function openConversationLock(string $conversationId)
    {
        $directory = storage_path('framework/cache/data');
        File::ensureDirectoryExists($directory);
        $lock = fopen($directory.'/assistant-conversation-'.hash('sha256', $conversationId).'.lock', 'c');
        if ($lock === false) {
            throw new ConversationLockUnavailable;
        }

        return $lock;
    }

    private function assertActiveAdmin(User $user): void
    {
        abort_unless($user->role === 'admin' && $user->is_active, 404);
    }
}
