<?php

namespace App\Services\AI;

use GuzzleHttp\Psr7\Stream;

/**
 * Sink respons provider dengan batas keras. Byte di atas batas tidak pernah
 * ditulis; transfer dipotong sehingga respons besar tidak pernah terbuffer
 * penuh maupun tersimpan ke disk.
 */
final class BoundedResponseSink extends Stream
{
    private int $written = 0;

    /** @param callable(): void $onExceeded */
    public function __construct(
        private readonly int $limit,
        private readonly mixed $onExceeded,
    ) {
        parent::__construct(fopen('php://temp', 'w+') ?: throw new \RuntimeException('Tidak dapat menyiapkan sink respons.'));
    }

    public function write($string): int
    {
        $incoming = strlen($string);

        if ($this->written + $incoming > $this->limit) {
            if (is_callable($this->onExceeded)) {
                ($this->onExceeded)();
            }

            return 0;
        }

        $written = parent::write($string);
        $this->written += $written;

        return $written;
    }
}
