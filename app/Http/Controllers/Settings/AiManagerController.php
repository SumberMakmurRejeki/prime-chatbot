<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Settings\AiManagerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AiManagerController extends Controller
{
    public function __construct(
        protected AiManagerService $aiManagerService,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->user()?->can('manage_ai')) {
            return redirect()->route('forbidden');
        }

        return view('pages.settings.ai-managers.index', [
            'actorName' => $request->user()?->name ?? 'User',
            'managers' => User::query()->where('manage_ai', true)->where('role', 'admin')->orderBy('name')->get(),
            'admins' => User::query()->where('role', 'admin')->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function grant(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'current_password' => ['required', 'current_password'],
        ]);
        $this->aiManagerService->grant($request, (int) $validated['target_user_id']);

        return redirect()->route('settings-ai-managers.index')->with('flash_success', 'Akses Pengelola AI diberikan.');
    }

    public function revoke(Request $request, int $userId): RedirectResponse
    {
        if (! $request->user()?->can('manage_ai')) {
            return redirect()->route('forbidden');
        }
        $this->validatePassword($request);
        $selfRevoked = $this->aiManagerService->revoke($request, $userId);
        if ($selfRevoked) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return redirect()->route('settings-ai-managers.index')->with('flash_success', 'Akses Pengelola AI dicabut.');
    }

    protected function validatePassword(Request $request): void
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
        ]);
    }
}
