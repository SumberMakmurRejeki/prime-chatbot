<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Settings\AiManagerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

#[Signature('ai:bootstrap-manager {user? : Username atau ID Admin aktif yang menerima akses AI.}')]
#[Description('Memberikan akses Pengelola AI kepada satu Admin aktif untuk pemulihan operasional.')]
class BootstrapAiManager extends Command
{
    public function __construct(
        protected AiManagerService $aiManagerService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $identifier = trim((string) $this->argument('user'));
        if ($identifier === '') {
            $this->error('Target Pengelola AI wajib diisi.');

            return self::FAILURE;
        }

        $target = ctype_digit($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->where('username', $identifier)->first();
        if (! $target) {
            $this->error('Target tidak ditemukan.');

            return self::FAILURE;
        }
        if ($target->role !== 'admin') {
            $this->error('Target harus berrole Admin.');

            return self::FAILURE;
        }
        if (! $target->is_active) {
            $this->error('Target harus aktif.');

            return self::FAILURE;
        }
        if (! $this->confirm('Grant AI management access to this user?')) {
            $this->warn('Pemberian akses dibatalkan.');

            return self::FAILURE;
        }

        $request = Request::create('/', 'POST');
        $request->setLaravelSession(app('session.store'));
        try {
            $this->aiManagerService->grant($request, $target->id, recovery: true);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info(sprintf('Akses Pengelola AI diberikan ke user ID %d.', $target->id));

        return self::SUCCESS;
    }
}
