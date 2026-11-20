<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use PDO;
use Throwable;

/** MariaDB queue. A gateway claim is never recycled into a printable state. */
final class PrintGateway
{
    private const CLAIM_SECONDS = 90;

    private const ROUTE = <<<'SQL'
        FROM print_jobs j
        JOIN printers p ON p.outlet_id = j.outlet_id AND p.printer_id = j.printer_id
        JOIN print_gateway_destinations d ON d.outlet_id = j.outlet_id AND d.printer_id = j.printer_id
        LEFT JOIN preparation_tasks t ON t.outlet_id = j.outlet_id AND t.order_id = j.order_id
            AND t.preparation_task_id = j.preparation_task_id
        WHERE d.gateway_id = :gateway AND j.outlet_id = :outlet
          AND j.adapter_type_snapshot = 'gateway' AND p.adapter_type = 'gateway' AND p.enabled = 1
          AND j.configuration_ref_snapshot = p.printer_code
          AND (
              (p.printer_code = 'CASHIER_PRINTER' AND p.role = 'cashier_receipt'
                  AND j.job_type = 'CASHIER_RECEIPT' AND j.preparation_task_id IS NULL)
              OR (p.role = 'station_kot' AND j.job_type = 'STATION_KOT' AND t.station_id = j.station_id AND (
                  (p.printer_code = 'GROUND_FLOOR_PRINTER' AND t.department_code_snapshot = 'GROUND_FLOOR')
                  OR (p.printer_code = 'FIRST_FLOOR_KITCHEN_PRINTER' AND t.department_code_snapshot = 'FIRST_FLOOR_KITCHEN')
              ))
          )
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function enabled(): bool
    {
        return Environment::get('PRINT_GATEWAY_ENABLED') === '1';
    }

    /** Null means an authenticated poll had no work. False means authentication failed. */
    public function claim(string $gateway, string $secret): array|false|null
    {
        return $this->authenticated($gateway, $secret, function (int $outlet) use ($gateway): ?array {
            $query = $this->pdo->prepare(
                'SELECT j.*, t.department_code_snapshot ' . self::ROUTE
                . " AND j.status IN ('queued', 'reprint_requested')"
                . ' AND EXISTS (SELECT 1 FROM payment_confirmations pc WHERE pc.order_id = j.order_id)'
                . ' ORDER BY j.print_job_id LIMIT 1 FOR UPDATE'
            );
            $query->execute(['gateway' => $gateway, 'outlet' => $outlet]);
            $job = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                return null;
            }
            $bytes = (new EscPosRenderer())->render(
                (string) $job['payload_text'],
                (int) $job['paper_width_mm'],
                (bool) $job['cut_after_print'],
            );
            $claim = bin2hex(random_bytes(32));
            $jobId = (int) $job['print_job_id'];
            $attempt = (int) $job['attempt_count'] + 1;
            $this->pdo->prepare("UPDATE print_jobs SET status = 'claimed', attempt_count = ?, "
                . 'last_error_category = NULL, last_error_message = NULL WHERE print_job_id = ?')
                ->execute([$attempt, $jobId]);
            $this->pdo->prepare(
                'INSERT INTO print_job_attempts (outlet_id, print_job_id, attempt_number, action, actor, '
                . 'gateway_id, claim_hash, claim_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, '
                . 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . self::CLAIM_SECONDS . ' SECOND))'
            )->execute([
                $outlet, $jobId, $attempt, $job['reprint_of_job_id'] === null ? 'PRINT' : 'REPRINT',
                $gateway, $gateway, hash('sha256', $claim),
            ]);
            return [
                'id' => $jobId,
                'claim' => $claim,
                'lease_seconds' => self::CLAIM_SECONDS,
                'destination' => (string) $job['configuration_ref_snapshot'],
                'type' => (string) $job['job_type'],
                'payload' => base64_encode($bytes),
                'sha256' => hash('sha256', $bytes),
            ];
        });
    }

    /** Return false for invalid credentials, null for an invalid or conflicting claim. */
    public function complete(string $gateway, string $secret, int $jobId, string $claim, string $result): bool|null
    {
        [$status, $category, $message] = match ($result) {
            'submitted' => ['submitted_unconfirmed', null, null],
            'failed' => ['failed', 'spooler_error', 'Gateway stopped before sending ticket bytes. Check the local destination.'],
            'needs_review' => ['needs_review', 'unknown', 'Paper output is uncertain. Inspect the printer before reprinting.'],
        };

        return $this->authenticated($gateway, $secret, function (int $outlet) use (
            $gateway, $jobId, $claim, $result, $status, $category, $message
        ): ?bool {
            $query = $this->pdo->prepare('SELECT j.status, j.attempt_count ' . self::ROUTE . ' AND j.print_job_id = :job FOR UPDATE');
            $query->execute(['gateway' => $gateway, 'outlet' => $outlet, 'job' => $jobId]);
            $job = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                return null;
            }
            $attempt = $this->pdo->prepare(
                'SELECT outcome, claim_hash, claim_expires_at > UTC_TIMESTAMP() AS valid_until '
                . 'FROM print_job_attempts WHERE outlet_id = ? AND print_job_id = ? AND attempt_number = ? '
                . 'AND gateway_id = ? FOR UPDATE'
            );
            $attempt->execute([$outlet, $jobId, $job['attempt_count'], $gateway]);
            $claimRow = $attempt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($claimRow) || !hash_equals((string) $claimRow['claim_hash'], hash('sha256', $claim))) {
                return null;
            }
            if ($claimRow['outcome'] !== null) {
                return $claimRow['outcome'] === $result ? true : null;
            }
            if ($job['status'] !== 'claimed' || (int) $claimRow['valid_until'] !== 1) {
                return null;
            }
            $this->pdo->prepare(
                'UPDATE print_jobs SET status = ?, last_error_category = ?, last_error_message = ? WHERE print_job_id = ?'
            )->execute([$status, $category, $message, $jobId]);
            $this->pdo->prepare(
                'UPDATE print_job_attempts SET outcome = ?, error_category = ?, error_message = ?, '
                . 'completed_at = CURRENT_TIMESTAMP WHERE outlet_id = ? AND print_job_id = ? AND attempt_number = ?'
            )->execute([$result, $category, $message, $outlet, $jobId, $job['attempt_count']]);
            return true;
        });
    }

    /** An expired claim may already have reached a printer. Never put it back in the queue. */
    public function flagExpiredClaims(int $outlet): void
    {
        $this->pdo->prepare(
            'UPDATE print_jobs j JOIN print_job_attempts a ON a.outlet_id = j.outlet_id '
            . 'AND a.print_job_id = j.print_job_id AND a.attempt_number = j.attempt_count '
            . "SET j.status = 'needs_review', j.last_error_category = 'timeout', "
            . "j.last_error_message = 'Gateway did not confirm submission. Check for paper before reprinting.', "
            . "a.outcome = 'needs_review', a.error_category = 'timeout', a.error_message = j.last_error_message, "
            . "a.completed_at = CURRENT_TIMESTAMP WHERE j.outlet_id = ? AND j.status = 'claimed' "
            . 'AND a.outcome IS NULL AND a.claim_expires_at <= UTC_TIMESTAMP()'
        )->execute([$outlet]);
    }

    public function cancelUnclaimed(int $outlet, int $jobId): bool
    {
        $query = $this->pdo->prepare(
            "UPDATE print_jobs SET status = 'cancelled', last_error_message = 'Cancelled by owner admin.' "
            . "WHERE outlet_id = ? AND print_job_id = ? AND adapter_type_snapshot = 'gateway' "
            . "AND status IN ('queued', 'reprint_requested')"
        );
        $query->execute([$outlet, $jobId]);
        return $query->rowCount() === 1;
    }

    private function authenticated(string $gateway, string $secret, callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare(
                'SELECT outlet_id, secret_hash FROM print_gateways WHERE gateway_id = ? AND revoked_at IS NULL FOR UPDATE'
            );
            $query->execute([$gateway]);
            $identity = $query->fetch(PDO::FETCH_ASSOC);
            $matches = hash_equals((string) ($identity['secret_hash'] ?? str_repeat('0', 64)), hash('sha256', $secret));
            $result = $matches && is_array($identity) ? $operation((int) $identity['outlet_id']) : false;
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
