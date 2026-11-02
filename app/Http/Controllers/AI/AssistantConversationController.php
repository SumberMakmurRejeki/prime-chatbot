<?php

namespace App\Http\Controllers\AI;

use App\Exceptions\ConversationLockUnavailable;
use App\Exceptions\ConversationRequestConflict;
use App\Exceptions\DocumentAnswerUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\AI\AppendConversationMessageRequest;
use App\Services\AI\AiConversationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AssistantConversationController extends Controller
{
    public function __construct(
        private AiConversationService $conversations,
    ) {}

    /** Halaman utama: New Chat dan daftar percakapan milik Admin yang login. */
    public function index(): View
    {
        return view('pages.assistant.index', [
            'conversations' => $this->conversations->listFor(auth()->user()),
        ]);
    }

    /** New Chat: membuat persis satu percakapan milik Admin yang login. */
    public function store(): RedirectResponse
    {
        $conversationId = $this->conversations->createFor(auth()->user());

        return redirect()->route('assistant.show', $conversationId);
    }

    /** Membuka kembali percakapan milik sendiri beserta riwayat terbatas. */
    public function show(string $conversationId): View
    {
        $cursor = request()->query('cursor');
        $data = $this->conversations->showFor(
            auth()->user(),
            $conversationId,
            is_string($cursor) ? $cursor : null,
        );

        return view('pages.assistant.show', $data);
    }

    /** Menyimpan pertanyaan user dan jawaban AI yang bersumber dari PDF terbit. */
    public function appendMessage(AppendConversationMessageRequest $request, string $conversationId): RedirectResponse|JsonResponse
    {
        try {
            $this->conversations->answerAndAppend(
                auth()->user(),
                $conversationId,
                (string) $request->validated('message'),
                (string) $request->validated('request_id'),
            );
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (ConversationRequestConflict $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], 409);
            }

            return back()->withInput()->with('flash_error', $exception->getMessage());
        } catch (DocumentAnswerUnavailable $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 503);
            }

            return back()->withInput()->with('flash_error', $exception->getMessage());
        } catch (ConversationLockUnavailable $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 503);
            }

            return back()->withInput()->with('flash_error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Jawaban tersimpan.', 'request_id' => (string) $request->validated('request_id')]);
        }

        return redirect()
            ->route('assistant.show', $conversationId)
            ->with('flash_success', 'Jawaban AI tersimpan.');
    }

    /** Hapus percakapan milik sendiri; hanya pemilik yang dapat menghapusnya. */
    public function destroy(Request $request, string $conversationId): RedirectResponse
    {
        try {
            $this->conversations->deleteFor(auth()->user(), $conversationId);
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (ConversationRequestConflict $exception) {
            return redirect()->route('assistant.show', $conversationId)->with('flash_error', $exception->getMessage());
        } catch (ConversationLockUnavailable $exception) {
            return redirect()->route('assistant.show', $conversationId)->with('flash_error', $exception->getMessage());
        }

        return redirect()
            ->route('assistant.index')
            ->with('flash_success', 'Percakapan dihapus.');
    }
}
