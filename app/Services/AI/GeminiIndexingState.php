<?php

namespace App\Services\AI;

/**
 * Status operasi indeksasi File Search yang sudah diklasifikasikan.
 *
 * Operasi hanya selesai bila Gemini menyatakan `done` sekaligus mengirim
 * identitas dokumen; respons 200 pada permintaan impor sendiri bukan bukti
 * indeksasi selesai.
 */
enum GeminiIndexingState: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    public function completed(): bool
    {
        return $this === self::Completed;
    }

    /**
     * @param  array{name: string, done: bool, document_name: ?string, error: ?array<string, mixed>}  $operation
     */
    public static function fromOperation(array $operation): self
    {
        return match (true) {
            $operation['error'] !== null => self::Failed,
            $operation['done'] && $operation['document_name'] !== null => self::Completed,
            $operation['done'] => self::Failed,
            default => self::Pending,
        };
    }
}
