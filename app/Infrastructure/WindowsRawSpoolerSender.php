<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use Kios\Domain\PrintErrorCategory;

final class WindowsRawSpoolerSender implements WindowsSpoolerSender
{
    public function __construct(
        private readonly string $powershellPath = 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
        private readonly int $timeoutSeconds = 5,
    ) {
    }

    public function send(string $printerName, string $bytes): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || !is_file($this->powershellPath) || $this->timeoutSeconds < 1 || $this->timeoutSeconds > 15) {
            throw new PrintAdapterFailure(PrintErrorCategory::SpoolerError, 'The Windows spooler adapter is unavailable.');
        }
        $script = dirname(__DIR__, 2) . '/bin/windows-raw-printer.ps1';
        if (!is_file($script)) {
            throw new PrintAdapterFailure(PrintErrorCategory::SpoolerError, 'The Windows spooler helper is unavailable.');
        }

        $payload = json_encode([
            'printerName' => $printerName,
            'bytesBase64' => base64_encode($bytes),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $stdin = base64_encode($payload) . "\n";
        $command = [$this->powershellPath, '-NoLogo', '-NoProfile', '-NonInteractive', '-File', $script];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $environment = [];
        foreach (['SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'PATH', 'PATHEXT', 'PSModulePath'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $environment[$key] = $value;
            }
        }
        $process = @proc_open($command, $descriptors, $pipes, null, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new PrintAdapterFailure(PrintErrorCategory::SpoolerError, 'The Windows spooler process could not start.');
        }

        try {
            $offset = 0;
            while ($offset < strlen($stdin)) {
                $written = fwrite($pipes[0], substr($stdin, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new PrintAdapterFailure(PrintErrorCategory::SpoolerError, 'The Windows spooler request could not be sent.');
                }
                $offset += $written;
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $stdout = '';
            $deadline = microtime(true) + $this->timeoutSeconds;
            do {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr = (string) stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process);
                    throw new PrintAdapterFailure(PrintErrorCategory::Timeout, 'The Windows printer did not respond before timeout.', false);
                }
                usleep(10_000);
            } while (true);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $result = json_decode(trim($stdout), true);
            if (!is_array($result) || ($result['success'] ?? false) !== true) {
                $category = match ($result['category'] ?? null) {
                    'printer_offline' => PrintErrorCategory::PrinterOffline,
                    'printer_not_found' => PrintErrorCategory::PrinterNotFound,
                    'timeout' => PrintErrorCategory::Timeout,
                    default => PrintErrorCategory::SpoolerError,
                };
                $retryable = in_array($category, [PrintErrorCategory::PrinterOffline, PrintErrorCategory::Timeout], true);
                throw new PrintAdapterFailure($category, 'The Windows printer did not accept the print job.', $retryable);
            }
        } finally {
            foreach ([1, 2] as $pipe) {
                if (isset($pipes[$pipe]) && is_resource($pipes[$pipe])) {
                    fclose($pipes[$pipe]);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
        }
    }
}
