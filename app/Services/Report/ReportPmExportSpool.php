<?php

namespace App\Services\Report;

use Generator;
use JsonException;
use RuntimeException;

class ReportPmExportSpool
{
    private const FORMAT_VERSION = 1;

    /** @var resource|null */
    private $stream;

    private string $buildingPath;

    private string $readyPath;

    private int $recordCount = 0;

    private bool $hasOpenParent = false;

    private bool $finalized = false;

    /** @var resource|null */
    private $hashContext;

    private function __construct(
        private readonly string $directory,
        private readonly int $reportExportId,
        bool $create,
    ) {
        $token = basename($directory);
        $this->buildingPath = $directory.'/'.$token.'.building';
        $this->readyPath = $directory.'/'.$token.'.ready';

        if (! $create) {
            return;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create PM export spool directory.');
        }

        $stream = fopen($this->buildingPath, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Unable to create PM export spool file.');
        }

        $this->stream = $stream;
        $this->hashContext = hash_init('sha256');
    }

    /**
     * Membuat spool building eksklusif untuk satu report_exports.
     *
     * @param  array<string, mixed>  $header
     */
    public static function open(int $reportExportId, array $header): self
    {
        $directory = storage_path('framework/report-pm-spool/'.$reportExportId.'-'.bin2hex(random_bytes(16)));
        $spool = new self($directory, $reportExportId, true);
        $spool->writeRecord(['type' => 'header', 'format_version' => self::FORMAT_VERSION] + $header);

        return $spool;
    }

    /**
     * Membuka kembali spool .ready untuk konsumsi; .building tidak pernah dibaca.
     */
    public static function openReady(string $readyPath, int $reportExportId): self
    {
        if (! str_ends_with($readyPath, '.ready') || ! is_file($readyPath)) {
            throw new RuntimeException('Completed PM export spool is missing.');
        }

        return new self(dirname($readyPath), $reportExportId, false);
    }

    /** @param array<string, mixed> $record */
    public function writeParent(array $record): void
    {
        $this->assertWritable();
        if ($this->hasOpenParent) {
            throw new RuntimeException('Previous PM export parent has no completed detail boundary.');
        }

        $this->writeRecord(['type' => 'parent'] + $record);
        $this->hasOpenParent = true;
    }

    /** @param array<string, mixed> $record */
    public function writeDetail(array $record): void
    {
        $this->assertWritable();
        if (! $this->hasOpenParent) {
            throw new RuntimeException('PM export detail cannot precede its parent.');
        }

        $this->writeRecord(['type' => 'detail'] + $record);
    }

    public function finishParent(): void
    {
        $this->assertWritable();
        if (! $this->hasOpenParent) {
            throw new RuntimeException('No PM export parent is open.');
        }

        $this->hasOpenParent = false;
    }

    /**
     * Menutup hash, flush, lalu rename atomik building -> ready.
     * Hanya hasil .ready yang sah dikonsumsi pembaca.
     */
    public function finalize(): string
    {
        $this->assertWritable();
        if ($this->hasOpenParent) {
            $this->finishParent();
        }

        $checksum = hash_final($this->hashContext);
        $this->hashContext = null;
        $this->writeLine(['type' => 'footer', 'record_count' => $this->recordCount, 'checksum' => $checksum], false);

        if (! fflush($this->stream) || ! fclose($this->stream)) {
            $this->stream = null;
            throw new RuntimeException('Unable to flush and close PM export spool.');
        }
        $this->stream = null;

        if (! rename($this->buildingPath, $this->readyPath)) {
            throw new RuntimeException('Unable to atomically finalize PM export spool.');
        }

        $this->finalized = true;

        return $this->readyPath;
    }

    /**
     * Streaming validasi header, urutan record, footer, record_count, dan checksum.
     * Spool yang terpotong/tidak lengkap selalu fatal.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function records(): Generator
    {
        if ($this->finalized || $this->stream !== null) {
            throw new RuntimeException('PM export spool is not a readable finalized spool.');
        }

        $reader = fopen($this->readyPath, 'rb');
        if ($reader === false) {
            throw new RuntimeException('Unable to open completed PM export spool.');
        }

        $hash = hash_init('sha256');
        $count = 0;
        $lineNumber = 0;
        $sawFooter = false;
        $parentIdentity = null;

        try {
            while (($line = fgets($reader)) !== false) {
                $lineNumber++;

                try {
                    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException('Malformed PM export spool record.', previous: $exception);
                }

                if (! is_array($record) || ! is_string($record['type'] ?? null)) {
                    throw new RuntimeException('Invalid PM export spool record shape.');
                }

                if ($lineNumber === 1) {
                    if ($record['type'] !== 'header'
                        || ($record['format_version'] ?? null) !== self::FORMAT_VERSION
                        || ($record['report_export_id'] ?? null) !== $this->reportExportId) {
                        throw new RuntimeException('Invalid PM export spool header.');
                    }
                } elseif ($sawFooter || $record['type'] === 'header') {
                    throw new RuntimeException('Invalid PM export spool record order.');
                }

                if ($record['type'] === 'footer') {
                    if (($record['record_count'] ?? null) === $count
                        && is_string($record['checksum'] ?? null)
                        && hash_equals($record['checksum'], hash_final($hash))) {
                        $sawFooter = true;

                        continue;
                    }

                    throw new RuntimeException('PM export spool footer validation failed.');
                }

                if ($record['type'] === 'parent') {
                    if (! is_int($record['schedule_date_id'] ?? null) || $record['schedule_date_id'] < 1
                        || ! array_key_exists('execution_id', $record)
                        || ($record['execution_id'] !== null && (! is_int($record['execution_id']) || $record['execution_id'] < 1))) {
                        throw new RuntimeException('Invalid PM export spool parent identity.');
                    }
                    $parentIdentity = [
                        'schedule_date_id' => $record['schedule_date_id'],
                        'execution_id' => $record['execution_id'],
                    ];
                } elseif ($record['type'] === 'detail') {
                    if ($parentIdentity === null) {
                        throw new RuntimeException('PM export spool detail has no parent.');
                    }
                    // Identitas beku wajib cocok sebelum detail diserahkan ke PDF/Excel.
                    if ($parentIdentity['execution_id'] === null
                        || ($record['schedule_date_id'] ?? null) !== $parentIdentity['schedule_date_id']
                        || ($record['execution_id'] ?? null) !== $parentIdentity['execution_id']
                        || ! is_int($record['execution_item_id'] ?? null) || $record['execution_item_id'] < 1) {
                        throw new RuntimeException('PM export spool detail identity does not match its parent.');
                    }
                } elseif ($record['type'] !== 'header') {
                    throw new RuntimeException('Unknown PM export spool record type.');
                }

                hash_update($hash, $line);
                $count++;

                if ($record['type'] !== 'header') {
                    yield $record;
                }
            }

            if (! feof($reader) || ! $sawFooter) {
                throw new RuntimeException('PM export spool is incomplete.');
            }
        } finally {
            fclose($reader);
        }
    }

    public function readyPath(): string
    {
        return $this->readyPath;
    }

    public function delete(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }

        foreach ([$this->buildingPath, $this->readyPath] as $path) {
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('Unable to delete PM export spool file.');
            }
        }

        if (is_dir($this->directory) && ! rmdir($this->directory)) {
            throw new RuntimeException('Unable to remove PM export spool directory.');
        }
    }

    private function assertWritable(): void
    {
        if ($this->stream === null || $this->finalized) {
            throw new RuntimeException('PM export spool is not writable.');
        }
    }

    /** @param array<string, mixed> $record */
    private function writeRecord(array $record): void
    {
        $this->recordCount++;
        $this->writeLine($record, true);
    }

    /** @param array<string, mixed> $record */
    private function writeLine(array $record, bool $includeInHash): void
    {
        try {
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode PM export spool record.', previous: $exception);
        }

        if (fwrite($this->stream, $line) !== strlen($line)) {
            throw new RuntimeException('Unable to write PM export spool record.');
        }

        if ($includeInHash) {
            hash_update($this->hashContext, $line);
        }
    }
}
