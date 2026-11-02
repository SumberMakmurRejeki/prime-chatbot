<?php

namespace App\Services\AI;

use RuntimeException;

/**
 * Resolves every A/AAAA answer in an isolated PHP child process with a hard
 * parent deadline. PHP dns_get_record is blocking and cannot be cancelled in
 * the request process; terminating this bounded child is the cancellation seam.
 */
final class BoundedDnsResolver
{
    public const DEADLINE_SECONDS = 3.0;

    private const MAX_OUTPUT_BYTES = 65536;

    /** @return list<string> */
    public function resolve(string $hostname): array
    {
        $worker = <<<'PHP'
            $records = @dns_get_record($argv[1], DNS_A | DNS_AAAA);
            $addresses = [];
            foreach (is_array($records) ? $records : [] as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $addresses[$address] = true;
                }
            }
            echo json_encode(array_keys($addresses), JSON_THROW_ON_ERROR);
            PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', $worker, $hostname],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('DNS resolver could not start.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = hrtime(true) + (int) (self::DEADLINE_SECONDS * 1_000_000_000);
        $output = '';
        $timedOut = false;

        do {
            $chunk = fread($pipes[1], 8192);
            if (is_string($chunk) && $chunk !== '') {
                $output .= $chunk;
            }

            fread($pipes[2], 8192);

            if (strlen($output) > self::MAX_OUTPUT_BYTES) {
                proc_terminate($process);
                throw new RuntimeException('DNS resolver returned excessive data.');
            }

            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }

            if (hrtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }

            usleep(10_000);
        } while (true);

        $output .= stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($timedOut) {
            throw new RuntimeException('DNS resolution exceeded its deadline.');
        }

        $addresses = json_decode($output, true);

        if (! is_array($addresses) || ! array_is_list($addresses)) {
            throw new RuntimeException('DNS resolver returned an invalid result.');
        }

        return array_values(array_unique(array_filter(
            $addresses,
            static fn (mixed $address): bool => is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false,
        )));
    }
}
