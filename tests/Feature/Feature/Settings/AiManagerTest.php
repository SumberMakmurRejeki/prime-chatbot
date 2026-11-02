<?php

namespace Tests\Feature\Feature\Settings;

use App\Models\User;
use App\Services\Settings\AiManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AiManagerTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $admin;

    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->makeUser('manager', 'admin', true, true);
        $this->admin = $this->makeUser('ordinary-admin', 'admin');
        $this->operator = $this->makeUser('operator', 'operator');
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
    }

    public function test_array_sessions_fail_closed_without_capability_change_or_success_audit(): void
    {
        $target = $this->makeUser('array-target', 'admin');
        config(['session.driver' => 'array']);
        app('session')->forgetDrivers();

        $response = $this->actingAs($this->manager)->post('/settings/ai/managers', [
            'target_user_id' => $target->id,
            'current_password' => 'password',
        ]);
        $response->assertSessionHasErrors('session');

        $revokeResponse = $this->actingAs($this->manager)->delete('/settings/ai/managers/'.$this->manager->id, [
            'current_password' => 'password',
        ]);
        $revokeResponse->assertSessionHasErrors('session');

        $this->assertTrue($this->manager->fresh()->manage_ai);
        $this->assertFalse($target->fresh()->manage_ai);
        $this->assertDatabaseMissing('user_activity_logs', ['module_name' => 'ai_manager', 'action' => 'grant']);
        $this->assertDatabaseMissing('user_activity_logs', ['module_name' => 'ai_manager', 'action' => 'revoke']);
    }

    public function test_only_active_capable_admin_can_view_manager_screen_and_sidebar_link(): void
    {
        $this->actingAs($this->operator)->get('/settings/ai/managers')->assertRedirect('/403');
        $this->actingAs($this->admin)->get('/settings/ai/managers')->assertForbidden();
        $this->actingAs($this->operator)->post('/settings/ai/managers', ['target_user_id' => $this->manager->id, 'current_password' => 'password'])->assertRedirect('/403');
        $this->actingAs($this->admin)->delete('/settings/ai/managers/'.$this->manager->id, ['current_password' => 'password'])->assertForbidden();
        $this->assertTrue($this->manager->fresh()->manage_ai);
        $managerPage = $this->actingAs($this->manager)->get('/settings/ai/managers');
        $managerPage->assertOk()
            ->assertSee('Pengelola AI')
            ->assertSee('Tambah Pengelola')
            ->assertSee('Admin aktif')
            ->assertSee('Password Anda saat ini')
            ->assertSee('Cabut Akses AI');
        $this->actingAs($this->manager)->get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('manageAi', true));
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('manageAi', false));
        $this->manager->forceFill(['is_active' => false])->save();
        $this->actingAs($this->manager)->get('/settings/ai/managers')->assertForbidden();
    }

    public function test_manager_grant_requires_password_and_active_admin_and_revokes_target_sessions(): void
    {
        $target = $this->makeUser('target', 'admin');
        $operator = $this->makeUser('active-op', 'operator');
        $inactiveAdmin = $this->makeUser('inactive-admin-grant', 'admin', false);
        $this->insertSessions($target, ['s1', 's2']);

        $this->actingAs($this->manager)->post('/settings/ai/managers', [
            'target_user_id' => $target->id,
            'current_password' => 'wrong',
        ])->assertSessionHasErrors('current_password');
        $this->assertFalse($target->fresh()->manage_ai);

        $this->actingAs($this->manager)->post('/settings/ai/managers', [
            'target_user_id' => $operator->id,
            'current_password' => 'password',
        ])->assertSessionHasErrors('user');
        $this->actingAs($this->manager)->post('/settings/ai/managers', [
            'target_user_id' => $inactiveAdmin->id,
            'current_password' => 'password',
        ])->assertSessionHasErrors('user');
        $this->actingAs($this->manager)->post('/settings/ai/managers', [
            'target_user_id' => $target->id,
            'current_password' => 'password',
        ])->assertRedirect();
        $this->assertTrue($target->fresh()->manage_ai);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseHas('user_activity_logs', ['module_name' => 'ai_manager', 'action' => 'grant']);
        $this->assertAuditRowExcludesSecrets('grant', $target->id, ['s1', 's2']);
        $log = DB::table('user_activity_logs')->where('module_name', 'ai_manager')->where('action', 'grant')->first();
        $this->assertSame(['manage_ai' => false], json_decode((string) $log->old_values, true));
        $this->assertSame(['manage_ai' => true], json_decode((string) $log->new_values, true));
    }

    public function test_revoking_another_manager_removes_sessions_and_audits_only_safe_capability_values(): void
    {
        $target = $this->makeUser('revoked-manager', 'admin', true, true);
        $this->insertSessions($target, ['revoked-session']);

        $this->actingAs($this->manager)->delete('/settings/ai/managers/'.$target->id, [
            'current_password' => 'password',
        ])->assertRedirect(route('settings-ai-managers.index'));

        $this->assertFalse($target->fresh()->manage_ai);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertAuditRowExcludesSecrets('revoke', $target->id, ['revoked-session']);
        $log = DB::table('user_activity_logs')->where('module_name', 'ai_manager')->where('action', 'revoke')->first();
        $this->assertSame(['manage_ai' => true], json_decode((string) $log->old_values, true));
        $this->assertSame(['manage_ai' => false], json_decode((string) $log->new_values, true));
    }

    public function test_manager_grant_deletes_sessions_from_configured_connection_and_table(): void
    {
        $connectionName = 'ai_session_test';
        $previousConnection = config('session.connection');
        $previousTable = config('session.table');
        $previousDatabaseConnection = config("database.connections.{$connectionName}");
        $target = $this->makeUser('custom-session-target', 'admin');
        $databasePath = tempnam(sys_get_temp_dir(), 'ai-session-test-');

        try {
            config([
                "database.connections.{$connectionName}" => [
                    'driver' => 'sqlite',
                    'database' => $databasePath,
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                ],
                'session.connection' => $connectionName,
                'session.table' => 'configured_sessions',
            ]);
            DB::purge($connectionName);
            DB::connection($connectionName)->statement('CREATE TABLE configured_sessions (id VARCHAR(255) PRIMARY KEY, user_id INTEGER)');
            DB::connection($connectionName)->table('configured_sessions')->insert([
                'id' => 'custom-session-row',
                'user_id' => $target->id,
            ]);

            $request = Request::create('/settings/ai/managers', 'POST');
            $request->setLaravelSession(app('session')->driver());
            app(AiManagerService::class)->grant($request, $target->id);

            $this->assertDatabaseMissing('configured_sessions', ['user_id' => $target->id], $connectionName);
            $this->assertTrue($target->fresh()->manage_ai);
        } finally {
            DB::disconnect($connectionName);
            DB::purge($connectionName);
            config([
                "database.connections.{$connectionName}" => $previousDatabaseConnection,
                'session.connection' => $previousConnection,
                'session.table' => $previousTable,
            ]);
            if (is_file($databasePath)) {
                unlink($databasePath);
            }
        }
    }

    public function test_revoke_requires_password_and_self_revoke_works_while_another_manager_remains(): void
    {
        $other = $this->makeUser('other-manager', 'admin', true, true);
        $this->insertSessions($this->manager, ['m1', 'm2']);
        $this->actingAs($this->manager)->delete('/settings/ai/managers/'.$this->manager->id, [
            'current_password' => 'bad',
        ])->assertSessionHasErrors('current_password');
        $this->assertTrue($this->manager->fresh()->manage_ai);

        $this->actingAs($this->manager)->delete('/settings/ai/managers/'.$this->manager->id, [
            'current_password' => 'password',
        ])->assertRedirect(route('login'));
        $this->assertFalse($this->manager->fresh()->manage_ai);
        $this->assertDatabaseMissing('sessions', ['user_id' => $this->manager->id]);
        $this->assertGuest();
        $this->assertTrue($other->fresh()->manage_ai);
        $this->assertDatabaseHas('user_activity_logs', ['module_name' => 'ai_manager', 'action' => 'revoke']);
    }

    public function test_last_manager_cannot_be_revoked_and_settings_cannot_change_protected_account(): void
    {
        $this->actingAs($this->manager)->delete('/settings/ai/managers/'.$this->manager->id, [
            'current_password' => 'password',
        ])->assertSessionHasErrors();
        $this->assertTrue($this->manager->fresh()->manage_ai);

        foreach ([
            fn () => $this->patch('/settings/users/'.$this->manager->id, ['name' => 'Changed', 'username' => 'manager', 'role' => 'operator', 'is_active' => 1]),
            fn () => $this->patch('/settings/users/'.$this->manager->id.'/reset-password', ['new_password' => 'new-password', 'new_password_confirmation' => 'new-password']),
            fn () => $this->patch('/settings/users/'.$this->manager->id.'/nonaktifkan'),
            fn () => $this->patch('/settings/users/'.$this->manager->id.'/aktifkan'),
            fn () => $this->delete('/settings/users/'.$this->manager->id),
        ] as $mutation) {
            $mutation()->assertSessionHasErrors();
        }
        $this->assertTrue($this->manager->fresh()->manage_ai);
    }

    public function test_user_settings_requests_cannot_assign_manage_ai_and_ordinary_admin_management_still_works(): void
    {
        $this->actingAs($this->admin)->post('/settings/users', [
            'name' => 'New Admin', 'username' => 'new-admin', 'password' => 'password123',
            'password_confirmation' => 'password123', 'role' => 'admin', 'is_active' => 1, 'manage_ai' => true,
        ])->assertRedirect();
        $this->assertFalse(User::query()->where('username', 'new-admin')->firstOrFail()->manage_ai);

        $this->actingAs($this->admin)->put('/settings/users/'.$this->manager->id, [
            'name' => 'Manager updated', 'username' => 'manager-new', 'role' => 'admin', 'is_active' => 1,
        ])->assertSessionHasErrors('user');

        $this->assertSame('manager', $this->manager->fresh()->username);

        $this->actingAs($this->admin)->put('/settings/users/'.$this->manager->id, [
            'name' => 'Manager display name updated', 'username' => 'manager', 'role' => 'admin', 'is_active' => 1,
        ])->assertRedirect();

        $this->assertSame('Manager display name updated', $this->manager->fresh()->name);
    }

    public function test_recovery_command_validates_identifier_target_and_confirmation_then_grants_and_revokes_sessions(): void
    {
        $target = $this->makeUser('recovery-target', 'admin');
        $inactiveAdmin = $this->makeUser('inactive-admin', 'admin', false);
        $this->insertSessions($target, ['r1', 'r2']);
        $this->artisan('ai:bootstrap-manager')->assertExitCode(1);
        $this->artisan('ai:bootstrap-manager', ['user' => 'missing-user'])->assertExitCode(1);
        $this->artisan('ai:bootstrap-manager', ['user' => $this->operator->username])->assertExitCode(1);
        $this->artisan('ai:bootstrap-manager', ['user' => $inactiveAdmin->username])->assertExitCode(1);
        $this->artisan('ai:bootstrap-manager', ['user' => $this->admin->username])->expectsConfirmation('Grant AI management access to this user?', 'no')->assertExitCode(1);
        $this->artisan('ai:bootstrap-manager', ['user' => $target->username])->expectsConfirmation('Grant AI management access to this user?', 'yes')->assertExitCode(0);

        $this->assertTrue($target->fresh()->manage_ai);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseHas('user_activity_logs', ['module_name' => 'ai_manager', 'action' => 'recovery_grant']);
        $this->assertAuditRowExcludesSecrets('recovery_grant', $target->id, ['r1', 'r2']);
    }

    /**
     * @param  list<string>  $sessionIds
     */
    private function assertAuditRowExcludesSecrets(string $action, int $targetId, array $sessionIds): void
    {
        $log = DB::table('user_activity_logs')->where('module_name', 'ai_manager')->where('action', $action)->first();
        $this->assertNotNull($log);
        $this->assertSame($targetId, (int) $log->record_id);
        $this->assertNotNull($log->created_at);
        $serialized = json_encode($log, JSON_THROW_ON_ERROR);
        foreach (['password', 'safe-test-payload', ...$sessionIds] as $secret) {
            $this->assertStringNotContainsString($secret, strtolower($serialized));
        }
    }

    private function makeUser(string $username, string $role, bool $active = true, bool $manager = false): User
    {
        $user = User::query()->create([
            'name' => $username,
            'username' => $username,
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => $active,
        ]);
        $user->forceFill(['manage_ai' => $manager])->save();

        return $user;
    }

    /** @param list<string> $ids */
    private function insertSessions(User $user, array $ids): void
    {
        foreach ($ids as $id) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => 'safe-test-payload',
                'last_activity' => now()->timestamp,
            ]);
        }
    }
}
