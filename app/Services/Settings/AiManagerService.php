<?php

namespace App\Services\Settings;

use App\Models\User;
use App\Services\Auth\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiManagerService
{
    public function __construct(
        protected ActivityLogService $activityLog,
    ) {}

    public function grant(Request $request, int $userId, bool $recovery = false): void
    {
        $this->ensureDatabaseSessions();
        DB::transaction(function () use ($request, $userId, $recovery): void {
            $this->lockActiveManagers();
            $target = User::query()->lockForUpdate()->find($userId);
            if (! $target || $target->role !== 'admin' || ! $target->is_active) {
                throw ValidationException::withMessages(['user' => 'Pilih Admin aktif yang tersedia.']);
            }

            $wasManager = $target->manage_ai;
            $target->forceFill(['manage_ai' => true])->save();
            $this->deleteSessions($target->id);
            $this->activityLog->log(
                request: $request,
                moduleName: 'ai_manager',
                action: $recovery ? 'recovery_grant' : 'grant',
                description: 'Perubahan akses Pengelola AI.',
                tableName: 'users',
                recordId: $target->id,
                oldValues: ['manage_ai' => $wasManager],
                newValues: ['manage_ai' => true],
            );
        });
    }

    public function revoke(Request $request, int $userId): bool
    {
        $this->ensureDatabaseSessions();
        $actorId = Auth::id();

        return DB::transaction(function () use ($request, $userId, $actorId): bool {
            $activeManagers = $this->lockActiveManagers();
            $target = User::query()->lockForUpdate()->find($userId);
            if (! $target || ! $target->manage_ai) {
                throw ValidationException::withMessages(['user' => 'Pengelola AI tidak ditemukan.']);
            }
            if (! $activeManagers->contains(fn (User $manager): bool => $manager->id !== $target->id)) {
                throw ValidationException::withMessages(['user' => 'Akses Pengelola AI terakhir tidak dapat dicabut.']);
            }

            $target->forceFill(['manage_ai' => false])->save();
            $this->deleteSessions($target->id);
            $this->activityLog->log(
                request: $request,
                moduleName: 'ai_manager',
                action: 'revoke',
                description: 'Perubahan akses Pengelola AI.',
                tableName: 'users',
                recordId: $target->id,
                oldValues: ['manage_ai' => true],
                newValues: ['manage_ai' => false],
            );

            return $target->id === $actorId;
        });
    }

    /**
     * Lock the active manager set before any target row in every lifecycle transaction.
     *
     * @return Collection<int, User>
     */
    protected function lockActiveManagers(): Collection
    {
        return User::query()
            ->where('manage_ai', true)
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    protected function deleteSessions(int $userId): void
    {
        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->delete();
    }

    protected function ensureDatabaseSessions(): void
    {
        if (config('session.driver') !== 'database') {
            throw ValidationException::withMessages([
                'session' => 'Perubahan akses AI memerlukan session driver database agar sesi lama dapat dicabut.',
            ]);
        }
    }
}
