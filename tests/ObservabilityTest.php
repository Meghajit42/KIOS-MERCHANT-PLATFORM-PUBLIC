<?php
declare(strict_types=1);

return [
    'local status failure returns generic 500 with a correlated sanitized private record' => static function (string $root): void {
        $logDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'kios-k7e-observability-' . bin2hex(random_bytes(8));
        $expectedLog = $logDirectory . DIRECTORY_SEPARATOR . 'errors.jsonl';
        $portSocket = @stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
        if (!is_resource($portSocket)) {
            throw new RuntimeException('could not reserve a loopback port for local observability test');
        }
        $address = stream_socket_get_name($portSocket, false);
        fclose($portSocket);
        if (!is_string($address) || !preg_match('/:(\d+)$/D', $address, $portMatch)) {
            throw new RuntimeException('could not identify local observability test port');
        }
        $port = (int) $portMatch[1];

        $environment = [];
        foreach (['SystemRoot', 'WINDIR', 'PATH', 'PATHEXT', 'TEMP', 'TMP', 'COMSPEC'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }
        $environment['KIOS_K7E_TEST_LOG_DIR'] = $logDirectory;
        $command = [
            PHP_BINARY,
            '-n',
            '-d', 'auto_prepend_file=' . $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'ObservabilityTestBootstrap.php',
            '-d', 'display_errors=0',
            '-d', 'log_errors=0',
            '-S', '127.0.0.1:' . $port,
            '-t', $root . DIRECTORY_SEPARATOR . 'public',
        ];
        $pipes = [];
        $serverOutputDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'kios-k7e-observability-server-' . bin2hex(random_bytes(8));
        $serverStdoutPath = $serverOutputDirectory . DIRECTORY_SEPARATOR . 'stdout.log';
        $serverStderrPath = $serverOutputDirectory . DIRECTORY_SEPARATOR . 'stderr.log';
        if (!@mkdir($serverOutputDirectory, 0700, true) && !is_dir($serverOutputDirectory)) {
            throw new RuntimeException('could not create isolated observability server output directory');
        }
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $serverStdoutPath, 'a'], 2 => ['file', $serverStderrPath, 'a']], $pipes, $root, $environment);
        if (!is_resource($process)) {
            foreach ([$serverStdoutPath, $serverStderrPath] as $path) {
                if (is_file($path) && !is_link($path)) {
                    @unlink($path);
                }
            }
            if (is_dir($serverOutputDirectory) && preg_match('/^kios-k7e-observability-server-[a-f0-9]{16}$/iD', basename($serverOutputDirectory)) === 1
                && realpath(dirname($serverOutputDirectory)) === realpath(sys_get_temp_dir())) {
                @rmdir($serverOutputDirectory);
            }
            throw new RuntimeException('could not start local observability test server');
        }
        $response = '';
        $serverOutput = '';
        try {
            fclose($pipes[0]);

            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $connectError, $connectMessage, 0.1);
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(50000);
            }
            if (!$ready) {
                $status = proc_get_status($process);
                $stderr = is_file($serverStderrPath) ? trim((string) file_get_contents($serverStderrPath)) : '';
                $stderr = preg_replace('/[\r\n\t]+/', ' ', $stderr) ?? '';
                $stderr = substr($stderr, 0, 500);
                throw new RuntimeException(
                    'local observability test server did not become ready'
                    . ' (running=' . (!empty($status['running']) ? 'yes' : 'no')
                    . ', exit=' . (string) ($status['exitcode'] ?? 'unknown')
                    . ($stderr !== '' ? ', stderr=' . $stderr : '')
                    . ')'
                );
            }

            $client = @stream_socket_client('tcp://127.0.0.1:' . $port, $connectError, $connectMessage, 3);
            if (!is_resource($client)) {
                throw new RuntimeException('local observability test request could not connect');
            }
            stream_set_timeout($client, 5);
            $request = "GET /index.php?token=G-106&_k7e_test_fault=status HTTP/1.1\r\n"
                . "Host: kios-test.local\r\nConnection: close\r\n"
                . "X-Kios-Request-Id: client-supplied-must-not-be-trusted\r\n"
                . "Authorization: Bearer KIOS_TEST_AUTH_SENTINEL\r\n"
                . "Cookie: session_id=KIOS_TEST_SESSION_ID_SENTINEL; csrf=KIOS_TEST_CSRF_SENTINEL; test_password=KIOS_TEST_PASSWORD_SENTINEL\r\n"
                . "X-CSRF-Token: KIOS_TEST_CSRF_SENTINEL\r\n\r\n";
            fwrite($client, $request);
            $deadline = microtime(true) + 10.0;
            $responseComplete = false;
            while (!feof($client) && microtime(true) < $deadline) {
                $chunk = fread($client, 8192);
                if ($chunk === false) {
                    break;
                }
                if ($chunk !== '') {
                    $response .= $chunk;
                }
                if (!empty(stream_get_meta_data($client)['timed_out'])) {
                    break;
                }
            }
            $responseComplete = feof($client);
            fclose($client);
            if (!$responseComplete) {
                throw new RuntimeException('local observability response did not terminate before timeout');
            }

            $parts = preg_split("/\r\n\r\n/", $response, 2);
            if (!is_array($parts) || count($parts) !== 2) {
                throw new RuntimeException('local observability response was malformed');
            }
            $headers = $parts[0];
            $body = $parts[1];
            if (!preg_match('/^HTTP\/1\.[01] 500\b/m', $headers)
                || !preg_match('/^X-Kios-Request-Id:\s*([a-f0-9]{32})\s*$/im', $headers, $idMatch)
                || $idMatch[1] === 'client-supplied-must-not-be-trusted'
                || !str_contains($body, 'Demo temporarily unavailable')
                || !str_contains($body, 'Please try again later.')
                || preg_match('/RuntimeException|ObservabilityTest|KIOS_TEST_|\.php:\d+|Stack trace|SQLSTATE/i', $body) === 1) {
                throw new RuntimeException('local observability response was not generic or correctly correlated');
            }
            if (!is_file($expectedLog) || !is_file(dirname($expectedLog) . DIRECTORY_SEPARATOR . '.errors.lock')) {
                throw new RuntimeException('local observability did not create its private log record');
            }

            $lines = file($expectedLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines) || count($lines) !== 1) {
                throw new RuntimeException('local observability log record count was unexpected');
            }
            $record = json_decode($lines[0], true);
            $requiredKeys = [
                'timestamp_utc', 'request_id', 'method', 'route', 'public_pickup_token',
                'error_type', 'exception_class', 'file', 'line', 'message', 'stack', 'php_version',
            ];
            if (!is_array($record) || array_diff($requiredKeys, array_keys($record)) !== []
                || $record['request_id'] !== $idMatch[1]
                || $record['method'] !== 'GET' || $record['route'] !== '/index.php'
                || $record['public_pickup_token'] !== 'G-106'
                || $record['error_type'] !== 'uncaught_exception'
                || $record['exception_class'] !== RuntimeException::class
                || $record['line'] < 1 || !str_ends_with((string) $record['file'], 'public' . DIRECTORY_SEPARATOR . 'index.php')
                || !is_array($record['stack']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string) $record['timestamp_utc'])) {
                throw new RuntimeException('private observability record was incomplete or not correlated');
            }

            $logContents = implode("\n", $lines);
            foreach ([
                'KIOS_TEST_DB_PASSWORD_SENTINEL', 'KIOS_TEST_SESSION_ID_SENTINEL',
                'KIOS_TEST_CSRF_SENTINEL', 'KIOS_TEST_AUTH_SENTINEL',
                'KIOS_TEST_COOKIE_SENTINEL', 'KIOS_TEST_PRIVATE_KEY_SENTINEL',
                'KIOS_TEST_PASSWORD_SENTINEL', 'client-supplied-must-not-be-trusted',
                'BEGIN PRIVATE KEY', 'END PRIVATE KEY',
            ] as $secretSentinel) {
                if (str_contains($logContents, $secretSentinel) || str_contains($body, $secretSentinel)) {
                    throw new RuntimeException('synthetic secret sentinel leaked into observability output');
                }
            }
            if (!str_contains((string) $record['message'], 'REDACTED')
                || str_contains($logContents, 'Authorization: Bearer') || str_contains($logContents, 'Cookie:')) {
                throw new RuntimeException('observability secret sanitization failed');
            }
            if (PHP_OS_FAMILY !== 'Windows' && ((fileperms($logDirectory) & 0777) !== 0700
                || (fileperms($expectedLog) & 0777) !== 0600
                || (fileperms(dirname($expectedLog) . DIRECTORY_SEPARATOR . '.errors.lock') & 0777) !== 0600)) {
                throw new RuntimeException('private observability permissions were not restricted');
            }
        } finally {
            @proc_terminate($process);
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            @proc_close($process);

            $stdoutSize = is_file($serverStdoutPath) && !is_link($serverStdoutPath) ? filesize($serverStdoutPath) : 0;
            $stderrSize = is_file($serverStderrPath) && !is_link($serverStderrPath) ? filesize($serverStderrPath) : 0;
            $serverOutputUnsafe = !is_int($stdoutSize) || !is_int($stderrSize) || $stdoutSize > 65536 || $stderrSize > 65536;
            if (!$serverOutputUnsafe) {
                $serverOutput = (is_file($serverStdoutPath) ? (string) file_get_contents($serverStdoutPath) : '')
                    . (is_file($serverStderrPath) ? (string) file_get_contents($serverStderrPath) : '');
                $serverOutputUnsafe = preg_match('/KIOS_TEST_|Authorization|Cookie:|Stack trace|SQLSTATE/i', $serverOutput) === 1;
            }

            if (is_dir($serverOutputDirectory) && preg_match('/^kios-k7e-observability-server-[a-f0-9]{16}$/iD', basename($serverOutputDirectory)) === 1
                && realpath(dirname($serverOutputDirectory)) === realpath(sys_get_temp_dir())) {
                foreach (['stdout.log', 'stderr.log'] as $file) {
                    $path = $serverOutputDirectory . DIRECTORY_SEPARATOR . $file;
                    if (is_file($path) && !is_link($path)) {
                        @unlink($path);
                    }
                }
                @rmdir($serverOutputDirectory);
            }

            if (is_dir($logDirectory) && preg_match('/^kios-k7e-observability-[a-f0-9]{16}$/iD', basename($logDirectory)) === 1
                && realpath(dirname($logDirectory)) === realpath(sys_get_temp_dir())) {
                foreach (['errors.jsonl', 'errors.jsonl.1', '.errors.lock'] as $file) {
                    $path = $logDirectory . DIRECTORY_SEPARATOR . $file;
                    if (is_file($path) && !is_link($path)) {
                        @unlink($path);
                    }
                }
                @rmdir($logDirectory);
            }
        }
        if ($serverOutputUnsafe) {
            throw new RuntimeException('local observability server output contained sensitive or excessive data');
        }
    },
    'observability retention is bounded and the default log path remains outside public webroot' => static function (string $root): void {
        $source = (string) file_get_contents($root . '/app/Infrastructure/RequestObservability.php');
        if (!str_contains($source, "private const MAX_LOG_BYTES = 262144;")
            || !str_contains($source, "private const LOG_NAME = 'errors.jsonl';")
            || !str_contains($source, "private const MAX_MESSAGE_BYTES = 600;")
            || !str_contains($source, "'storage' . DIRECTORY_SEPARATOR . 'logs'")
            || str_contains($source, "'public' . DIRECTORY_SEPARATOR . 'storage'")) {
            throw new RuntimeException('observability retention or private path policy changed');
        }
    },
];
