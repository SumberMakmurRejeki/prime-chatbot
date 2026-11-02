<?php

namespace App\Services\AI\DocumentIngestion;

use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Extractor PDF lokal (AI-3B1).
 *
 * Kontrak: pdftotext -layout -enc UTF-8 -eol unix -cfg privat, input lokal,
 * stdout dibatasi keras, tanpa shell, tanpa OCR, tanpa fallback extractor.
 * Binary TIDAK dihardcode: path konfigurasi hanya nama/binary yang disediakan
 * environment produksi, override lokal dilakukan lewat env/ukuran proses.
 */
class PdfTextExtractor
{
    private mixed $processRunner;

    public function __construct(
        private readonly PdfIngestionConfig $config,
        ?callable $processRunner = null,
    ) {
        $this->processRunner = $processRunner ?? [$this, 'runWithSymfonyProcess'];
    }

    public function extract(string $sourcePath): ExtractedPdfText
    {
        $this->assertIntakeSafe($sourcePath);

        // Satu pembacaan byte: hash dan ekstraksi wajib memakai snapshot yang
        // sama supaya sourceSha256 selalu mendeskripsikan byte yang diekstraksi.
        $sourceHandle = fopen($sourcePath, 'rb');
        if ($sourceHandle === false) {
            throw PdfIngestionException::intakeRejected();
        }
        try {
            $readLimit = $this->config->maxSourceBytes >= PHP_INT_MAX ? PHP_INT_MAX : $this->config->maxSourceBytes + 1;
            $sourceBytes = stream_get_contents($sourceHandle, $readLimit);
        } finally {
            fclose($sourceHandle);
        }
        if ($sourceBytes === false) {
            throw PdfIngestionException::intakeRejected();
        }
        if (strlen($sourceBytes) > $this->config->maxSourceBytes) {
            throw PdfIngestionException::sourceTooLarge();
        }
        if (strlen($sourceBytes) < 5 || substr($sourceBytes, 0, 5) !== '%PDF-') {
            throw PdfIngestionException::unsupportedContent();
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($sourceBytes);
        if (! is_string($mime) || ! str_starts_with($mime, 'application/pdf')) {
            throw PdfIngestionException::intakeRejected();
        }
        $sourceSha256 = hash('sha256', $sourceBytes);

        // Snapshot privat dari byte yang sama; tempnam membuat berkas dengan mode
        // aman sehingga tidak ada jendela race pada path yang bisa ditebak.
        $snapshotPath = tempnam(sys_get_temp_dir(), 'prime-pdf-src-');
        if ($snapshotPath === false) {
            throw PdfIngestionException::extractionFailed();
        }
        @chmod($snapshotPath, 0600);

        try {
            if (file_put_contents($snapshotPath, $sourceBytes) !== strlen($sourceBytes)) {
                throw PdfIngestionException::extractionFailed();
            }

            $configPath = $this->writePrivateConfig();
            try {
                $outcome = $this->invokeExtractor($snapshotPath, $configPath);
            } finally {
                @unlink($configPath);
            }
        } finally {
            @unlink($snapshotPath);
        }

        return $this->splitPages($outcome, $sourceSha256);
    }

    private function assertIntakeSafe(string $sourcePath): int
    {
        if ($sourcePath === ''
            || str_contains($sourcePath, "\0")
            || preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $sourcePath) === 1
            || str_starts_with($sourcePath, '\\\\')) {
            throw PdfIngestionException::intakeRejected();
        }

        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw PdfIngestionException::intakeRejected();
        }

        if (strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) !== 'pdf') {
            throw PdfIngestionException::intakeRejected();
        }

        $size = filesize($sourcePath);
        if ($size === false || $size === 0) {
            throw PdfIngestionException::intakeRejected();
        }

        if ($size > $this->config->maxSourceBytes) {
            throw PdfIngestionException::sourceTooLarge();
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath);
        if (! is_string($mime) || str_starts_with($mime, 'application/pdf') === false) {
            throw PdfIngestionException::intakeRejected();
        }

        $handle = fopen($sourcePath, 'rb');
        if ($handle === false) {
            throw PdfIngestionException::intakeRejected();
        }
        try {
            if (fread($handle, 5) !== '%PDF-') {
                throw PdfIngestionException::unsupportedContent();
            }
        } finally {
            fclose($handle);
        }

        return $size;
    }

    private function writePrivateConfig(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'prime-pdf-cfg-');
        if ($path === false) {
            throw PdfIngestionException::extractionFailed();
        }
        // Konfigurasi privat minimal: mencegah extractor mewarisi settings
        // arbitrari dari xpdfrc pengguna agar hasil ekstraksi deterministik.
        if (file_put_contents($path, "# PRIME private extraction config\n") === false) {
            @unlink($path);
            throw PdfIngestionException::extractionFailed();
        }

        return $path;
    }

    /** @return array{stdout: string, stderr: string} */
    private function invokeExtractor(string $sourcePath, string $configPath): array
    {
        $command = [
            $this->config->binary,
            '-cfg', $configPath,
            '-layout',
            '-enc', 'UTF-8',
            '-eol', 'unix',
            $sourcePath,
            '-',
        ];

        try {
            $outcome = ($this->processRunner)(
                $command,
                (float) $this->config->timeoutSeconds,
                $this->config->maxTotalTextBytes,
                $this->config->maxStderrBytes,
            );
        } catch (ProcessTimedOutException) {
            throw PdfIngestionException::extractionTimeout();
        } catch (ProcessStartFailedException|ProcessSignaledException) {
            throw PdfIngestionException::extractionFailed();
        }

        if (! is_array($outcome)
            || ! isset($outcome['stdout'], $outcome['stderr'], $outcome['exit_code'])
            || ! is_string($outcome['stdout'])
            || ! is_string($outcome['stderr'])
            || ! is_int($outcome['exit_code'])) {
            throw PdfIngestionException::extractionFailed();
        }

        if (strlen($outcome['stdout']) > $this->config->maxTotalTextBytes
            || strlen($outcome['stderr']) > $this->config->maxStderrBytes) {
            throw PdfIngestionException::extractedTextTooLarge();
        }
        if ($outcome['exit_code'] !== 0) {
            throw PdfIngestionException::extractionFailed();
        }

        return $outcome;

    }

    /**
     * Runner default: Symfony Process murni array argumen tanpa shell.
     *
     * Output internal proses dinonaktifkan (disableOutput) sehingga memori
     * hanya tumbuh sebesar buffer terbatas di callback, bukan buffer penuh.
     */
    private function runWithSymfonyProcess(
        array $command,
        float $timeoutSeconds,
        int $maxStdoutBytes,
        int $maxStderrBytes,
    ): array {
        $process = new Process($command, timeout: $timeoutSeconds);
        $process->disableOutput();

        $state = ['stdout' => '', 'stderr' => '', 'stdoutBytes' => 0, 'stderrBytes' => 0, 'overflow' => false];
        $kill = defined('SIGKILL') ? SIGKILL : 9;

        try {
            $exitCode = $process->run(function (string $type, string $data) use ($process, $kill, $maxStderrBytes, $maxStdoutBytes, &$state): void {
                if ($type === Process::ERR) {
                    $free = max(0, $maxStderrBytes - $state['stderrBytes']);
                    $state['stderr'] .= substr($data, 0, $free);
                    $state['stderrBytes'] += strlen($data);
                } else {
                    $free = max(0, $maxStdoutBytes - $state['stdoutBytes']);
                    $state['stdout'] .= substr($data, 0, $free);
                    $state['stdoutBytes'] += strlen($data);
                }

                if ($state['stdoutBytes'] > $maxStdoutBytes || $state['stderrBytes'] > $maxStderrBytes) {
                    $state['overflow'] = true;
                    $process->stop(0.0, $kill);
                }
            });
        } catch (ProcessSignaledException) {
            if ($state['overflow']) {
                throw PdfIngestionException::extractedTextTooLarge();
            }

            throw PdfIngestionException::extractionFailed();
        }

        if ($state['overflow']) {
            throw PdfIngestionException::extractedTextTooLarge();
        }

        return [
            'stdout' => $state['stdout'],
            'stderr' => $state['stderr'],
            'exit_code' => $exitCode,
        ];
    }

    private function splitPages(array $outcome, string $sourceSha256): ExtractedPdfText
    {
        $stdout = $outcome['stdout'];

        if (! mb_check_encoding($stdout, 'UTF-8')) {
            throw PdfIngestionException::invalidUtf8();
        }

        if (strlen($stdout) > $this->config->maxTotalTextBytes) {
            throw PdfIngestionException::extractedTextTooLarge();
        }

        // Hapus HANYA segmen kosong terakhir akibat form feed penutup supaya
        // nomor halaman fisik tetap satu-based dan jujur.
        $segments = explode("\f", $stdout);
        if ($segments !== [] && end($segments) === '') {
            array_pop($segments);
        }

        if ($segments === [] || $segments === ['']) {
            throw PdfIngestionException::unsupportedContent();
        }

        if (count($segments) > $this->config->maxPages) {
            throw PdfIngestionException::pageLimitExceeded();
        }

        $pages = [];
        $hasText = false;
        foreach ($segments as $index => $pageText) {
            if (strlen($pageText) > $this->config->maxPageTextBytes) {
                throw PdfIngestionException::pageTextTooLarge();
            }
            if (trim($pageText) !== '') {
                $hasText = true;
            }
            $pages[] = new ExtractedPdfPage($index + 1, $pageText);
        }

        if (! $hasText) {
            throw PdfIngestionException::unsupportedContent();
        }

        return new ExtractedPdfText($pages, $sourceSha256);
    }
}
