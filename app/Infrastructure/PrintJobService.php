<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use DomainException;
use Kios\Application\ReceiptTextRenderer;
use Kios\Application\PrintText;
use Kios\Application\StationKotTextRenderer;
use Kios\Domain\PrintErrorCategory;
use Kios\Domain\PrintJob;
use Kios\Domain\PrintJobAction;
use Kios\Domain\PrintJobStatus;
use Kios\Domain\PrintJobType;
use Kios\Domain\PrinterAdapterType;
use Kios\Domain\PrinterRole;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class PrintJobService
{
    public const MAX_ATTEMPTS_PER_CYCLE = 3;
    private const BACKOFF_MICROSECONDS = [100_000, 250_000];
    private const STALE_AFTER_SECONDS = 120;

    private readonly PrintAdapter $virtualAdapter;
    private readonly ?PrintAdapter $windowsAdapter;
    private readonly EscPosRenderer $escPos;
    private bool $sqliteClaimTransactionOpen = false;

    public function __construct(
        private readonly PDO $pdo,
        ?PrintAdapter $virtualAdapter = null,
        ?PrintAdapter $windowsAdapter = null,
        ?EscPosRenderer $escPos = null,
        private readonly ?\Closure $authorization = null,
        private readonly bool $virtualOnly = false,
    ) {
        $configuredSpool = getenv('KIOS_VIRTUAL_PRINT_SPOOL');
        $spool = is_string($configuredSpool) && trim($configuredSpool) !== ''
            ? $configuredSpool
            : dirname(__DIR__, 2) . '/storage/runtime/print-spool';
        $this->virtualAdapter = $virtualAdapter ?? new VirtualPrinterAdapter($spool);
        $this->windowsAdapter = $windowsAdapter;
        $this->escPos = $escPos ?? new EscPosRenderer();
    }

    /** Must be called inside the payment/preparation transaction. @return list<int> new queued job IDs */
    public function enqueueForOrder(int $orderId, int $outletId): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Print jobs must be persisted with the order transaction.');
        }
        if ($orderId < 1 || $outletId < 1) {
            throw new DomainException('A valid order and outlet are required for print routing.');
        }

        $orderQuery = $this->pdo->prepare(
            'SELECT o.order_id, o.outlet_id, o.token, COALESCE(o.pickup_token, o.token) AS display_token, '
            . 'o.order_type, o.status, o.total_paise, o.created_at, d.name AS outlet_name '
            . 'FROM orders o JOIN outlets d ON d.outlet_id = o.outlet_id '
            . 'WHERE o.order_id = :order_id AND o.outlet_id = :outlet_id AND d.active = 1'
        );
        $orderQuery->execute(['order_id' => $orderId, 'outlet_id' => $outletId]);
        $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order) || $order['status'] !== 'preparing') {
            throw new DomainException('Only a persisted order in preparation can create automatic print jobs.');
        }

        $itemsQuery = $this->pdo->prepare(
            'SELECT product_name_snapshot, variant_label_snapshot, unit_snapshot, quantity, unit_price_paise, line_total_paise '
            . 'FROM order_items WHERE outlet_id = :outlet_id AND order_id = :order_id ORDER BY order_item_id'
        );
        $itemsQuery->execute(['outlet_id' => $outletId, 'order_id' => $orderId]);
        $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        if ($items === []) {
            throw new RuntimeException('The persisted order has no receipt item snapshots.');
        }

        $paymentQuery = $this->pdo->prepare(
            'SELECT payment_method, confirmed_at FROM payment_confirmations WHERE order_id = :order_id'
        );
        $paymentQuery->execute(['order_id' => $orderId]);
        $payment = $paymentQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($payment)) {
            throw new RuntimeException('The confirmed payment snapshot is unavailable for receipt generation.');
        }

        $receiptRenderError = null;
        try {
            $receiptCopies = (new ReceiptTextRenderer())->renderCopies(
                (string) $order['outlet_name'],
                (string) $order['display_token'],
                (string) $order['order_type'],
                (int) $order['total_paise'],
                (string) $payment['payment_method'],
                (string) $payment['confirmed_at'],
                $items,
            );
        } catch (Throwable) {
            $receiptRenderError = 'Receipt text could not be rendered from the stored order snapshots.';
            $safeToken = PrintText::line((string) $order['display_token']);
            $receiptCopies = array_map(
                static fn(string $copyLabel): array => [
                    'copyLabel' => $copyLabel,
                    'text' => "CASHIER RECEIPT\n" . $copyLabel . "\nTOKEN " . $safeToken . "\nPRINT RENDER FAILED - CHECK ORDER SNAPSHOTS\n",
                ],
                ['CUSTOMER COPY', 'COUNTER COPY'],
            );
        }
        $legacyReceiptQuery = $this->pdo->prepare(
            'SELECT payload_text FROM print_jobs WHERE outlet_id = :outlet_id AND order_id = :order_id '
            . 'AND job_type = :job_type AND idempotency_key = :legacy_key'
        );
        $legacyReceiptQuery->execute([
            'outlet_id' => $outletId,
            'order_id' => $orderId,
            'job_type' => PrintJobType::CashierReceipt->value,
            'legacy_key' => "auto:receipt:$outletId:$orderId",
        ]);
        $legacyReceiptText = $legacyReceiptQuery->fetchColumn();
        if (is_string($legacyReceiptText)) {
            preg_match_all('/^(CUSTOMER COPY|COUNTER COPY)$/m', $legacyReceiptText, $legacyLabels);
            if (array_values(array_unique($legacyLabels[1] ?? [])) !== ['CUSTOMER COPY', 'COUNTER COPY']) {
                throw new DomainException('An older automatic receipt job has an incomplete copy label. Inspect it before creating another receipt.');
            }
            // A previously qualified combined receipt already carries both logical copies.
            // Keep it idempotent across upgrade; do not create a second physical obligation.
            $receiptCopies = [];
        }
        $receiptPrinter = $this->receiptPrinter($outletId);
        $queued = [];
        foreach ($receiptCopies as $receiptCopy) {
            $copyKey = strtolower(str_replace(' ', '-', $receiptCopy['copyLabel']));
            $receipt = $this->insertJob([
                'outletId' => $outletId,
                'orderId' => $orderId,
                'taskId' => null,
                'stationId' => null,
                'jobType' => PrintJobType::CashierReceipt,
                'idempotencyKey' => "auto:receipt:$copyKey:$outletId:$orderId",
                'token' => (string) $order['display_token'],
                'stationCode' => null,
                'stationName' => null,
                'printer' => $receiptPrinter,
                'payloadText' => $receiptCopy['text'],
                'reprintOf' => null,
                'requestedBy' => null,
                'reprintReason' => null,
                'initialErrorCategory' => $receiptRenderError === null ? null : PrintErrorCategory::RenderFailed,
                'initialErrorMessage' => $receiptRenderError,
            ]);
            if ($receipt['created'] && $receipt['status'] === PrintJobStatus::Queued->value) {
                $queued[] = $receipt['id'];
            }
        }

        return array_merge($queued,$this->enqueueStationKots($orderId,$outletId,$order));
    }

    /** Digital KOT snapshots only. Never renders a receipt or dispatches a physical printer. */
    public function enqueueInternalKots(int $orderId,int $outletId):array
    {
        if(!$this->pdo->inTransaction()||!$this->virtualOnly)throw new DomainException('Internal virtual transaction required.');
        $q=$this->pdo->prepare("SELECT o.*,COALESCE(o.pickup_token,o.token) AS display_token,d.name AS outlet_name FROM orders o JOIN outlets d ON d.outlet_id=o.outlet_id WHERE o.order_id=? AND o.outlet_id=? AND o.order_mode='INTERNAL_SERVICE' AND o.status='preparing'");
        $q->execute([$orderId,$outletId]);$order=$q->fetch(PDO::FETCH_ASSOC);if(!$order)throw new DomainException('Internal preparation snapshot required.');
        return $this->enqueueStationKots($orderId,$outletId,$order);
    }

    private function enqueueStationKots(int $orderId,int $outletId,array $order):array
    {
        $queued=[];
        $taskQuery = $this->pdo->prepare(
            'SELECT pt.preparation_task_id, pt.station_id, ps.code AS station_code, ps.name AS station_name '
            . 'FROM preparation_tasks pt JOIN preparation_stations ps '
            . 'ON ps.outlet_id = pt.outlet_id AND ps.station_id = pt.station_id '
            . 'WHERE pt.outlet_id = :outlet_id AND pt.order_id = :order_id AND ps.active = 1 '
            . 'ORDER BY ps.code, pt.preparation_task_id'
        );
        $taskQuery->execute(['outlet_id' => $outletId, 'order_id' => $orderId]);
        $tasks = $taskQuery->fetchAll(PDO::FETCH_ASSOC);
        if ($tasks === []) {
            throw new RuntimeException('The paid order has no persisted station tasks for KOT routing.');
        }

        $taskItemsQuery = $this->pdo->prepare(
            'SELECT oi.order_item_id, oi.product_name_snapshot, oi.variant_label_snapshot, oi.quantity '
            . 'FROM preparation_task_items pti '
            . 'JOIN order_items oi ON oi.outlet_id = pti.outlet_id AND oi.order_id = pti.order_id '
            . 'AND oi.order_item_id = pti.order_item_id '
            . 'WHERE pti.outlet_id = :outlet_id AND pti.order_id = :order_id '
            . 'AND pti.preparation_task_id = :task_id ORDER BY oi.order_item_id'
        );
        foreach ($tasks as $task) {
            $taskId = (int) $task['preparation_task_id'];
            $taskItemsQuery->execute(['outlet_id' => $outletId, 'order_id' => $orderId, 'task_id' => $taskId]);
            $taskItems = $taskItemsQuery->fetchAll(PDO::FETCH_ASSOC);
            if ($taskItems === []) {
                throw new RuntimeException('A station task has no immutable item snapshots.');
            }
            if(($order['order_mode']??'CUSTOMER')==='INTERNAL_SERVICE'){
                $n=$this->pdo->prepare('SELECT notes FROM merchant_service_line_notes WHERE outlet_id=? AND order_id=? AND order_item_id=?');
                foreach($taskItems as &$item){$n->execute([$outletId,$orderId,$item['order_item_id']]);$note=$n->fetchColumn();if(is_string($note)&&$note!=='')$item['product_name_snapshot'].=' · '.PrintText::line($note);}unset($item);
            }
            $kotRenderError = null;
            try {
                $kotText = (new StationKotTextRenderer())->render(
                    (string) $order['outlet_name'],
                    (string) $task['station_name'],
                    (string) $task['station_code'],
                    (string) $order['display_token'],
                    (string) $order['order_type'],
                    (string) $order['created_at'],
                    $taskItems,
                );
            } catch (Throwable $error) {
                if(($order['order_mode']??'CUSTOMER')==='INTERNAL_SERVICE')throw $error;
                $kotText = "KITCHEN ORDER TICKET\nTOKEN " . PrintText::line((string) $order['display_token']) . "\nSTATION " . PrintText::line((string) $task['station_code']) . "\nPRINT RENDER FAILED - CHECK STATION DASHBOARD\n";
                $kotRenderError = 'Station KOT text could not be rendered from the task snapshots.';
            }
            if(($order['order_mode']??'CUSTOMER')==='INTERNAL_SERVICE'){
                $q=$this->pdo->prepare('SELECT t.name FROM merchant_service_sessions s JOIN merchant_seating_tables t ON t.merchant_id=s.merchant_id AND t.outlet_id=s.outlet_id AND t.table_id=s.table_id WHERE s.legacy_outlet_id=? AND s.order_id=?');$q->execute([$outletId,$orderId]);$table=$q->fetchColumn();$kotText="INTERNAL SERVICE - NO PAYMENT DUE\n".($table!==false?'TABLE '.PrintText::line($table)."\n":'').$kotText;
            }
            $printer = $this->stationPrinter($outletId, (int) $task['station_id']);
            $kot = $this->insertJob([
                'outletId' => $outletId,
                'orderId' => $orderId,
                'taskId' => $taskId,
                'stationId' => (int) $task['station_id'],
                'jobType' => PrintJobType::StationKot,
                'idempotencyKey' => "auto:kot:$outletId:$taskId",
                'token' => (string) $order['display_token'],
                'stationCode' => (string) $task['station_code'],
                'stationName' => (string) $task['station_name'],
                'printer' => $printer,
                'payloadText' => $kotText,
                'reprintOf' => null,
                'requestedBy' => null,
                'reprintReason' => null,
                'initialErrorCategory' => $kotRenderError === null ? null : PrintErrorCategory::RenderFailed,
                'initialErrorMessage' => $kotRenderError,
            ]);
            if ($kot['created'] && $kot['status'] === PrintJobStatus::Queued->value) {
                $queued[] = $kot['id'];
            }
        }

        return $queued;
    }

    /** Deliver one job with at most three attempts in this automatic/manual cycle. */
    public function dispatch(int $jobId, int $outletId, PrintJobAction $action = PrintJobAction::Print, ?string $actor = null): PrintJobStatus
    {
        for ($cycleAttempt = 1; $cycleAttempt <= self::MAX_ATTEMPTS_PER_CYCLE; $cycleAttempt++) {
            $job = $this->claim($jobId, $outletId, $action, $actor);
            if ($job === null) {
                return $this->statusFor($jobId, $outletId) ?? PrintJobStatus::Failed;
            }

            try {
                if ($job->adapterType === null || $job->printerId === null) {
                    throw new PrintAdapterFailure(PrintErrorCategory::NoPrinterConfigured, 'No enabled printer is configured for this job.');
                }
                try {
                    $bytes = $this->escPos->render($job->payloadText, $job->paperWidthMm, $job->cutAfterPrint);
                } catch (Throwable) {
                    throw new PrintAdapterFailure(PrintErrorCategory::RenderFailed, 'Print data could not be rendered for the configured paper width.');
                }
                $adapter = $this->adapterFor($job);
                $reference = $adapter->print($job, $bytes);
                $this->finishSuccess($job, $reference);
                return PrintJobStatus::Printed;
            } catch (PrintAdapterFailure $error) {
                $retry = $error->retryable && $cycleAttempt < self::MAX_ATTEMPTS_PER_CYCLE;
                $this->finishFailure(
                    $job,
                    $error->category,
                    $error->getMessage(),
                    $retry ? PrintJobStatus::RetryPending : PrintJobStatus::Failed,
                );
                if (!$retry) {
                    return PrintJobStatus::Failed;
                }
            } catch (Throwable) {
                $retry = $cycleAttempt < self::MAX_ATTEMPTS_PER_CYCLE;
                $this->finishFailure(
                    $job,
                    PrintErrorCategory::Unknown,
                    'The print operation failed unexpectedly.',
                    $retry ? PrintJobStatus::RetryPending : PrintJobStatus::Failed,
                );
                if (!$retry) {
                    return PrintJobStatus::Failed;
                }
            }

            usleep(self::BACKOFF_MICROSECONDS[min($cycleAttempt - 1, count(self::BACKOFF_MICROSECONDS) - 1)]);
        }
        return PrintJobStatus::Failed;
    }

    /** Staff retry reuses the same immutable job and refreshes only its server-side printer route. */
    public function retry(int $jobId, int $outletId, string $actor): PrintJobStatus
    {
        $job = $this->getJob($jobId, $outletId);
        if ($job === null || !in_array($job['status'], [PrintJobStatus::Failed->value, PrintJobStatus::RetryPending->value, PrintJobStatus::Queued->value], true)) {
            throw new DomainException('Only a failed or pending print job can be retried.');
        }
        $printer = $this->printerForJobRow($job, $outletId);
        if ($job['adapter_type_snapshot'] === 'gateway' || ($printer['adapter_type'] ?? null) === 'gateway') {
            throw new DomainException('Inspect the destination and request an audited reprint from the owner print queue.');
        }
        if ($printer === null) {
            $this->recordUnavailableAttempt($job, $outletId, PrintJobAction::Retry, $actor);
            return PrintJobStatus::Failed;
        }
        $this->assignPrinterForRetry($job, $outletId, $printer);
        return $this->dispatch($jobId, $outletId, PrintJobAction::Retry, $actor);
    }

    /** Deliberate new copy with an immutable source link, actor and reason. */
    public function reprint(int $jobId, int $outletId, string $actor, string $reason, ?string $requestId = null): int
    {
        $reason = trim($reason);
        if (strlen($reason) < 3 || strlen($reason) > 240 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)) {
            throw new DomainException('Enter a short reason for the reprint.');
        }
        $source = $this->getJob($jobId, $outletId);
        if ($source === null || !in_array($source['status'], ['printed', 'failed', 'submitted_unconfirmed', 'needs_review'], true)) {
            throw new DomainException('Only a completed or failed print can be deliberately reprinted.');
        }
        $printer = $this->printerForJobRow($source, $outletId);
        $gateway = $source['adapter_type_snapshot'] === 'gateway' || ($printer['adapter_type'] ?? null) === 'gateway';
        if ($actor === 'owner-admin' && !$gateway) {
            throw new DomainException('The owner gateway queue requires a gateway destination.');
        }
        if ($gateway && ($actor !== 'owner-admin' || !PrintGateway::enabled()
            || ($printer['adapter_type'] ?? null) !== 'gateway'
            || ($source['last_error_category'] ?? null) === 'render_failed'
            || ($source['adapter_type_snapshot'] === 'gateway' && (int) $source['printer_id'] !== (int) $printer['printer_id'])
            || $requestId === null)) {
            throw new DomainException('Gateway reprints require owner review and a matching enabled destination.');
        }
        if ($requestId !== null && preg_match('/^[a-f0-9]{32}$/D', $requestId) !== 1) {
            throw new DomainException('Reload the print queue before requesting a reprint.');
        }
        $payload = "*** REPRINT COPY ***\nREASON: " . substr($reason, 0, 240) . "\n\n" . (string) $source['payload_text'];
        $idempotency = 'reprint:' . $jobId . ':' . ($requestId ?? bin2hex(random_bytes(16)));
        $this->beginClaimTransaction();
        try {
            $created = $this->insertJob([
            'outletId' => $outletId,
            'orderId' => (int) $source['order_id'],
            'taskId' => $source['preparation_task_id'] === null ? null : (int) $source['preparation_task_id'],
            'stationId' => $source['station_id'] === null ? null : (int) $source['station_id'],
            'jobType' => PrintJobType::from((string) $source['job_type']),
            'idempotencyKey' => $idempotency,
            'token' => (string) $source['token_snapshot'],
            'stationCode' => $source['station_code_snapshot'] === null ? null : (string) $source['station_code_snapshot'],
            'stationName' => $source['station_name_snapshot'] === null ? null : (string) $source['station_name_snapshot'],
            'printer' => $printer,
            'payloadText' => $payload,
            'reprintOf' => $jobId,
            'requestedBy' => substr($actor, 0, 80),
            'reprintReason' => substr($reason, 0, 240),
            ]);
            $this->commitClaimTransaction();
        } catch (Throwable $error) {
            $this->rollbackClaimTransaction();
            throw $error;
        }
        if (!$created['created']) {
            return $created['id'];
        }
        if ($printer === null || $gateway) {
            return $created['id'];
        }
        $this->dispatch($created['id'], $outletId, PrintJobAction::Reprint, $actor);
        return $created['id'];
    }

    /** @return list<array<string, mixed>> */
    public function listForOutlet(int $outletId, int $limit = 100, ?string $publicOrder = null): array
    {
        if ($outletId < 1) {
            throw new DomainException('A valid outlet is required for the print queue.');
        }
        $this->recoverStalePrinting($outletId);
        if (PrintGateway::enabled()) {
            (new PrintGateway($this->pdo))->flagExpiredClaims($outletId);
        }
        $limit = max(1, min($limit, 200));
        $query = $this->pdo->prepare(
            'SELECT print_job_id, token_snapshot, job_type, station_code_snapshot, payload_text, '
            . 'COALESCE(printer_name_snapshot, :missing_printer) AS printer_name, status, attempt_count, '
            . 'last_error_category, last_error_message, reprint_of_job_id, reprint_reason, requested_by, created_at, adapter_type_snapshot '
            . 'FROM print_jobs WHERE outlet_id = :outlet_id '
            . ($publicOrder === null ? '' : 'AND order_id IN (SELECT order_id FROM orders WHERE public_id = :public_order) ')
            . 'ORDER BY created_at DESC, print_job_id DESC LIMIT ' . $limit
        );
        $query->execute(['missing_printer' => 'No printer configured', 'outlet_id' => $outletId]
            + ($publicOrder === null ? [] : ['public_order' => $publicOrder]));
        $jobs = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($jobs as &$job) {
            $copyLabels = [];
            if (($job['job_type'] ?? null) === PrintJobType::CashierReceipt->value
                && preg_match_all('/^(CUSTOMER COPY|COUNTER COPY)$/m', (string) ($job['payload_text'] ?? ''), $matches) > 0) {
                $copyLabels = array_values(array_unique($matches[1]));
            }
            $job['copy_label'] = match (count($copyLabels)) {
                1 => $copyLabels[0],
                2 => 'Combined legacy copies',
                default => null,
            };
            unset($job['payload_text']);
        }
        unset($job);
        return $jobs;
    }

    /** Mark interrupted sends failed without automatic resend; inspect the printer before a staff retry. */
    public function recoverStalePrinting(int $outletId): int
    {
        if ($outletId < 1) {
            throw new DomainException('A valid outlet is required for print recovery.');
        }
        $suffix = $this->driver() === 'mysql' ? ' FOR UPDATE' : '';
        $sql = $this->driver() === 'mysql'
            ? "SELECT print_job_id, attempt_count FROM print_jobs WHERE outlet_id = :outlet_id AND status = 'printing' AND updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL " . self::STALE_AFTER_SECONDS . ' SECOND)' . $suffix
            : "SELECT print_job_id, attempt_count FROM print_jobs WHERE outlet_id = :outlet_id AND status = 'printing' AND updated_at < datetime('now', '-" . self::STALE_AFTER_SECONDS . " seconds')" . $suffix;
        $this->beginClaimTransaction();
        try {
            $query = $this->pdo->prepare($sql);
            $query->execute(['outlet_id' => $outletId]);
            $stale = $query->fetchAll(PDO::FETCH_ASSOC);
            $updateJob = $this->pdo->prepare(
                "UPDATE print_jobs SET status = 'failed', last_error_category = :category, last_error_message = :message "
                . "WHERE outlet_id = :outlet_id AND print_job_id = :job_id AND status = 'printing'"
            );
            $updateAttempt = $this->pdo->prepare(
                "UPDATE print_job_attempts SET outcome = 'failed', error_category = :category, error_message = :message, completed_at = CURRENT_TIMESTAMP "
                . 'WHERE outlet_id = :outlet_id AND print_job_id = :job_id AND attempt_number = :attempt '
                . 'AND completed_at IS NULL'
            );
            foreach ($stale as $row) {
                $params = [
                    'category' => PrintErrorCategory::Timeout->value,
                    'message' => 'Print outcome is uncertain after a restart; inspect output before retrying.',
                    'outlet_id' => $outletId,
                    'job_id' => (int) $row['print_job_id'],
                ];
                $updateJob->execute($params);
                $updateAttempt->execute($params + ['attempt' => (int) $row['attempt_count']]);
            }
            $this->commitClaimTransaction();
            return count($stale);
        } catch (Throwable $error) {
            $this->rollbackClaimTransaction();
            throw $error;
        }
    }

    /** @return array<string, mixed>|null */
    private function receiptPrinter(int $outletId): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT printer_id, name, adapter_type, configuration_ref, paper_width_mm, cut_after_print '
            . 'FROM printers WHERE outlet_id = :outlet_id AND role = :role AND enabled = 1 ORDER BY printer_id LIMIT 1'
        );
        $query->execute(['outlet_id' => $outletId, 'role' => PrinterRole::CashierReceipt->value]);
        $printer = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($printer) ? $printer : null;
    }

    /** @return array<string, mixed>|null */
    private function stationPrinter(int $outletId, int $stationId): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT p.printer_id, p.name, p.adapter_type, p.configuration_ref, p.paper_width_mm, p.cut_after_print '
            . 'FROM printer_station_routes r JOIN printers p ON p.outlet_id = r.outlet_id AND p.printer_id = r.printer_id '
            . 'WHERE r.outlet_id = :outlet_id AND r.station_id = :station_id '
            . 'AND p.role = :role AND p.enabled = 1'
        );
        $query->execute(['outlet_id' => $outletId, 'station_id' => $stationId, 'role' => PrinterRole::StationKot->value]);
        $printer = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($printer) ? $printer : null;
    }

    /** @param array<string, mixed> $job @return array<string, mixed>|null */
    private function printerForJobRow(array $job, int $outletId): ?array
    {
        if ($job['job_type'] === PrintJobType::CashierReceipt->value) {
            return $this->receiptPrinter($outletId);
        }
        if ($job['station_id'] === null) {
            return null;
        }
        return $this->stationPrinter($outletId, (int) $job['station_id']);
    }

    /** @param array<string, mixed> $input @return array{id:int,created:bool,status:string} */
    private function insertJob(array $input): array
    {
        $printer = $input['printer'];
        $initialErrorCategory = $input['initialErrorCategory'] ?? null;
        $initialErrorMessage = $input['initialErrorMessage'] ?? null;
        $category = $initialErrorCategory instanceof PrintErrorCategory
            ? $initialErrorCategory->value
            : ($printer === null ? PrintErrorCategory::NoPrinterConfigured->value : null);
        $message = is_string($initialErrorMessage)
            ? substr($initialErrorMessage, 0, 180)
            : ($printer === null ? 'No enabled printer is configured for this job.' : null);
        $status = $category === null
            ? (($printer['adapter_type'] ?? null) === 'gateway' && $input['reprintOf'] !== null ? 'reprint_requested' : 'queued')
            : 'failed';
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO print_jobs (outlet_id, order_id, preparation_task_id, station_id, printer_id, job_type, status, '
                . 'idempotency_key, reprint_of_job_id, requested_by, reprint_reason, token_snapshot, station_code_snapshot, '
                . 'station_name_snapshot, printer_name_snapshot, adapter_type_snapshot, configuration_ref_snapshot, '
                . 'paper_width_mm, cut_after_print, payload_text, last_error_category, last_error_message) '
                . 'VALUES (:outlet_id, :order_id, :task_id, :station_id, :printer_id, :job_type, :status, :idempotency, '
                . ':reprint_of, :requested_by, :reprint_reason, :token, :station_code, :station_name, :printer_name, '
                . ':adapter, :configuration_ref, :paper_width, :cut, :payload, :error_category, :error_message)'
            );
            $insert->execute([
                'outlet_id' => $input['outletId'],
                'order_id' => $input['orderId'],
                'task_id' => $input['taskId'],
                'station_id' => $input['stationId'],
                'printer_id' => $printer['printer_id'] ?? null,
                'job_type' => $input['jobType']->value,
                'status' => $status,
                'idempotency' => $input['idempotencyKey'],
                'reprint_of' => $input['reprintOf'],
                'requested_by' => $input['requestedBy'],
                'reprint_reason' => $input['reprintReason'],
                'token' => $input['token'],
                'station_code' => $input['stationCode'],
                'station_name' => $input['stationName'],
                'printer_name' => $printer['name'] ?? null,
                'adapter' => $printer['adapter_type'] ?? null,
                'configuration_ref' => $printer['configuration_ref'] ?? null,
                'paper_width' => (int) ($printer['paper_width_mm'] ?? 80),
                'cut' => (int) ($printer['cut_after_print'] ?? 1),
                'payload' => $input['payloadText'],
                'error_category' => $category,
                'error_message' => $message,
            ]);
        } catch (PDOException $error) {
            $query = $this->pdo->prepare('SELECT print_job_id, status FROM print_jobs WHERE idempotency_key = :key');
            $query->execute(['key' => $input['idempotencyKey']]);
            $existing = $query->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                return ['id' => (int) $existing['print_job_id'], 'created' => false, 'status' => (string) $existing['status']];
            }
            throw $error;
        }
        $id = (int) $this->pdo->lastInsertId();
        if ($status === PrintJobStatus::Failed->value) {
            $this->pdo->prepare('UPDATE print_jobs SET attempt_count = 1 WHERE print_job_id = :job AND outlet_id = :outlet')
                ->execute(['job' => $id, 'outlet' => (int) $input['outletId']]);
            $attempt = $this->pdo->prepare(
                "INSERT INTO print_job_attempts (outlet_id, print_job_id, attempt_number, action, actor, outcome, error_category, error_message, completed_at) "
                . "VALUES (:outlet, :job, 1, :action, :actor, 'failed', :category, :message, CURRENT_TIMESTAMP)"
            );
            $attempt->execute([
                'outlet' => (int) $input['outletId'],
                'job' => $id,
                'action' => $input['reprintOf'] === null ? PrintJobAction::Print->value : PrintJobAction::Reprint->value,
                'actor' => $input['requestedBy'],
                'category' => $category,
                'message' => $message,
            ]);
        }
        return ['id' => $id, 'created' => true, 'status' => $status];
    }

    private function claim(int $jobId, int $outletId, PrintJobAction $action, ?string $actor): ?PrintJob
    {
        $suffix = $this->driver() === 'mysql' ? ' FOR UPDATE' : '';
        $this->beginClaimTransaction();
        try {
            $query = $this->pdo->prepare('SELECT * FROM print_jobs WHERE print_job_id = :job_id AND outlet_id = :outlet_id' . $suffix);
            $query->execute(['job_id' => $jobId, 'outlet_id' => $outletId]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->commitClaimTransaction();
                return null;
            }
            $status = PrintJobStatus::from((string) $row['status']);
            if ($row['adapter_type_snapshot'] === 'gateway'
                || !in_array($status, [PrintJobStatus::Queued, PrintJobStatus::RetryPending], true)) {
                $this->commitClaimTransaction();
                return null;
            }
            if ($row['printer_id'] === null || $row['adapter_type_snapshot'] === null) {
                $this->markUnconfiguredInsideTransaction($row, $outletId, $action, $actor);
                $this->commitClaimTransaction();
                return null;
            }
            $printerCheck = $this->pdo->prepare(
                'SELECT enabled, role FROM printers WHERE outlet_id = :outlet_id AND printer_id = :printer_id'
            );
            $printerCheck->execute(['outlet_id' => $outletId, 'printer_id' => (int) $row['printer_id']]);
            $configuredPrinter = $printerCheck->fetch(PDO::FETCH_ASSOC);
            $expectedRole = $row['job_type'] === PrintJobType::CashierReceipt->value
                ? PrinterRole::CashierReceipt->value
                : PrinterRole::StationKot->value;
            if (!is_array($configuredPrinter) || (int) $configuredPrinter['enabled'] !== 1 || $configuredPrinter['role'] !== $expectedRole) {
                $this->finishUnavailableInsideTransaction($row, $outletId, $action, $actor, PrintErrorCategory::PrinterNotFound, 'The configured printer is unavailable.');
                $this->commitClaimTransaction();
                return null;
            }

            $status->transitionTo(PrintJobStatus::Printing);
            $attemptNumber = (int) $row['attempt_count'] + 1;
            $update = $this->pdo->prepare(
                "UPDATE print_jobs SET status = 'printing', attempt_count = :attempt, last_error_category = NULL, last_error_message = NULL "
                . 'WHERE print_job_id = :job_id AND outlet_id = :outlet_id AND status IN (\'queued\', \'retry_pending\')'
            );
            $update->execute(['attempt' => $attemptNumber, 'job_id' => $jobId, 'outlet_id' => $outletId]);
            if ($update->rowCount() !== 1) {
                $this->rollbackClaimTransaction();
                return null;
            }
            $attempt = $this->pdo->prepare(
                'INSERT INTO print_job_attempts (outlet_id, print_job_id, attempt_number, action, actor) '
                . 'VALUES (:outlet_id, :job_id, :attempt, :action, :actor)'
            );
            $attempt->execute([
                'outlet_id' => $outletId,
                'job_id' => $jobId,
                'attempt' => $attemptNumber,
                'action' => $action->value,
                'actor' => $actor === null ? null : substr($actor, 0, 80),
            ]);
            $this->commitClaimTransaction();
            $row['status'] = PrintJobStatus::Printing->value;
            $row['attempt_count'] = $attemptNumber;
            return $this->toDomain($row, $outletId);
        } catch (Throwable $error) {
            $this->rollbackClaimTransaction();
            throw $error;
        }
    }

    private function finishSuccess(PrintJob $job, string $reference): void
    {
        $this->beginClaimTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT status, attempt_count FROM print_jobs WHERE print_job_id = :job AND outlet_id = :outlet' . ($this->driver() === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute(['job' => $job->id, 'outlet' => $job->outletId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['status'] !== PrintJobStatus::Printing->value || (int) $row['attempt_count'] !== $job->attemptCount) {
                throw new RuntimeException('The claimed print job changed before completion.');
            }
            PrintJobStatus::Printing->transitionTo(PrintJobStatus::Printed);
            $update = $this->pdo->prepare(
                "UPDATE print_jobs SET status = 'printed', printed_at = CURRENT_TIMESTAMP, last_error_category = NULL, last_error_message = NULL "
                . 'WHERE print_job_id = :job AND outlet_id = :outlet AND status = \'printing\''
            );
            $update->execute(['job' => $job->id, 'outlet' => $job->outletId]);
            $attempt = $this->pdo->prepare(
                "UPDATE print_job_attempts SET outcome = 'printed', spool_reference = :reference, completed_at = CURRENT_TIMESTAMP "
                . 'WHERE print_job_id = :job AND outlet_id = :outlet AND attempt_number = :number AND outcome IS NULL'
            );
            $attempt->execute([
                'reference' => substr($reference, 0, 255),
                'job' => $job->id,
                'outlet' => $job->outletId,
                'number' => $job->attemptCount,
            ]);
            if ($attempt->rowCount() !== 1) {
                throw new RuntimeException('The print attempt could not be finalized.');
            }
            $this->commitClaimTransaction();
        } catch (Throwable $error) {
            $this->rollbackClaimTransaction();
            throw $error;
        }
    }

    private function finishFailure(PrintJob $job, PrintErrorCategory $category, string $message, PrintJobStatus $next): void
    {
        if (!in_array($next, [PrintJobStatus::RetryPending, PrintJobStatus::Failed], true)) {
            throw new RuntimeException('A print failure requested an invalid next state.');
        }
        PrintJobStatus::Printing->transitionTo($next);
        $message = substr($message, 0, 180);
        $this->beginClaimTransaction();
        try {
            $update = $this->pdo->prepare(
                'UPDATE print_jobs SET status = :status, last_error_category = :category, last_error_message = :message '
                . 'WHERE print_job_id = :job AND outlet_id = :outlet AND status = :current AND attempt_count = :attempt'
            );
            $update->execute([
                'status' => $next->value,
                'category' => $category->value,
                'message' => $message,
                'job' => $job->id,
                'outlet' => $job->outletId,
                'current' => PrintJobStatus::Printing->value,
                'attempt' => $job->attemptCount,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The claimed print failure could not be persisted.');
            }
            $attempt = $this->pdo->prepare(
                "UPDATE print_job_attempts SET outcome = 'failed', error_category = :category, error_message = :message, completed_at = CURRENT_TIMESTAMP "
                . 'WHERE print_job_id = :job AND outlet_id = :outlet AND attempt_number = :attempt AND outcome IS NULL'
            );
            $attempt->execute([
                'category' => $category->value,
                'message' => $message,
                'job' => $job->id,
                'outlet' => $job->outletId,
                'attempt' => $job->attemptCount,
            ]);
            if ($attempt->rowCount() !== 1) {
                throw new RuntimeException('The failed print attempt could not be persisted.');
            }
            $this->commitClaimTransaction();
        } catch (Throwable $error) {
            $this->rollbackClaimTransaction();
            throw $error;
        }
    }

    /** @param array<string, mixed> $row */
    private function markUnconfiguredInsideTransaction(array $row, int $outletId, PrintJobAction $action, ?string $actor): void
    {
        $this->finishUnavailableInsideTransaction($row, $outletId, $action, $actor, PrintErrorCategory::NoPrinterConfigured, 'No enabled printer is configured for this job.');
    }

    /** @param array<string, mixed> $row */
    private function finishUnavailableInsideTransaction(
        array $row,
        int $outletId,
        PrintJobAction $action,
        ?string $actor,
        PrintErrorCategory $category,
        string $message,
    ): void {
        $attemptNumber = (int) $row['attempt_count'] + 1;
        $status = PrintJobStatus::from((string) $row['status']);
        if ($status !== PrintJobStatus::Failed) {
            $status->transitionTo(PrintJobStatus::RetryPending);
            PrintJobStatus::RetryPending->transitionTo(PrintJobStatus::Failed);
        }
        $update = $this->pdo->prepare(
            "UPDATE print_jobs SET status = 'failed', attempt_count = :attempt, last_error_category = :category, last_error_message = :message "
            . 'WHERE print_job_id = :job AND outlet_id = :outlet'
        );
        $update->execute([
            'attempt' => $attemptNumber,
            'category' => $category->value,
            'message' => $message,
            'job' => (int) $row['print_job_id'],
            'outlet' => $outletId,
        ]);
        $attempt = $this->pdo->prepare(
            "INSERT INTO print_job_attempts (outlet_id, print_job_id, attempt_number, action, actor, outcome, error_category, error_message, completed_at) "
            . "VALUES (:outlet, :job, :number, :action, :actor, 'failed', :category, :message, CURRENT_TIMESTAMP)"
        );
        $attempt->execute([
            'outlet' => $outletId,
            'job' => (int) $row['print_job_id'],
            'number' => $attemptNumber,
            'action' => $action->value,
            'actor' => $actor === null ? null : substr($actor, 0, 80),
            'category' => $category->value,
            'message' => $message,
        ]);
    }

    /** @param array<string, mixed> $job */
    private function recordUnavailableAttempt(array $job, int $outletId, PrintJobAction $action, string $actor): void
    {
        if (!isset($job['print_job_id'])) {
            throw new RuntimeException('The print job disappeared before the staff action.');
        }
        $this->beginClaimTransaction();
        try {
            $query = $this->pdo->prepare('SELECT * FROM print_jobs WHERE print_job_id = :job AND outlet_id = :outlet' . ($this->driver() === 'mysql' ? ' FOR UPDATE' : ''));
            $query->execute(['job' => (int) $job['print_job_id'], 'outlet' => $outletId]);
            $current = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current)) {
                throw new RuntimeException('The print job disappeared before the staff action.');
            }
            $status = PrintJobStatus::from((string) $current['status']);
            if (!in_array($status, [PrintJobStatus::Failed, PrintJobStatus::Queued, PrintJobStatus::RetryPending], true)) {
                throw new DomainException('This print job can no longer be retried.');
            }
            $this->finishUnavailableInsideTransaction($current, $outletId, $action, $actor, PrintErrorCategory::NoPrinterConfigured, 'No enabled printer is configured for this job.');
            $this->commitClaimTransaction();
        } catch (Throwable $error) {
            $this->rollbackClaimTransaction();
            throw $error;
        }
    }

    /** @param array<string, mixed> $job @param array<string, mixed> $printer */
    private function assignPrinterForRetry(array $job, int $outletId, array $printer): void
    {
        $this->beginClaimTransaction();
        try {
            $query = $this->pdo->prepare('SELECT status FROM print_jobs WHERE print_job_id = :job AND outlet_id = :outlet' . ($this->driver() === 'mysql' ? ' FOR UPDATE' : ''));
            $query->execute(['job' => (int) $job['print_job_id'], 'outlet' => $outletId]);
            $status = $query->fetchColumn();
            if (!is_string($status) || !in_array($status, [PrintJobStatus::Failed->value, PrintJobStatus::RetryPending->value, PrintJobStatus::Queued->value], true)) {
                throw new DomainException('This print job can no longer be retried.');
            }
            $current = PrintJobStatus::from($status);
            if ($current === PrintJobStatus::Failed) {
                $current->transitionTo(PrintJobStatus::RetryPending);
            }
            $update = $this->pdo->prepare(
                "UPDATE print_jobs SET printer_id = :printer_id, printer_name_snapshot = :printer_name, "
                . 'adapter_type_snapshot = :adapter, configuration_ref_snapshot = :configuration_ref, '
                . "paper_width_mm = :paper_width, cut_after_print = :cut, status = 'retry_pending', "
                . 'last_error_category = NULL, last_error_message = NULL '
                . 'WHERE print_job_id = :job AND outlet_id = :outlet'
            );
            $update->execute([
                'printer_id' => (int) $printer['printer_id'],
                'printer_name' => (string) $printer['name'],
                'adapter' => (string) $printer['adapter_type'],
                'configuration_ref' => $printer['configuration_ref'],
                'paper_width' => (int) $printer['paper_width_mm'],
                'cut' => (int) $printer['cut_after_print'],
                'job' => (int) $job['print_job_id'],
                'outlet' => $outletId,
            ]);
            $this->commitClaimTransaction();
        } catch (Throwable $error) {
            $this->rollbackClaimTransaction();
            throw $error;
        }
    }

    /** @return array<string, mixed>|null */
    private function getJob(int $jobId, int $outletId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM print_jobs WHERE print_job_id = :job AND outlet_id = :outlet');
        $query->execute(['job' => $jobId, 'outlet' => $outletId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function statusFor(int $jobId, int $outletId): ?PrintJobStatus
    {
        $query = $this->pdo->prepare('SELECT status FROM print_jobs WHERE print_job_id = :job AND outlet_id = :outlet');
        $query->execute(['job' => $jobId, 'outlet' => $outletId]);
        $status = $query->fetchColumn();
        return is_string($status) ? PrintJobStatus::tryFrom($status) : null;
    }

    /** @param array<string, mixed> $row */
    private function toDomain(array $row, int $outletId): PrintJob
    {
        $adapter = is_string($row['adapter_type_snapshot']) ? PrinterAdapterType::tryFrom($row['adapter_type_snapshot']) : null;
        return new PrintJob(
            (int) $row['print_job_id'],
            $outletId,
            (int) $row['order_id'],
            $row['preparation_task_id'] === null ? null : (int) $row['preparation_task_id'],
            $row['station_id'] === null ? null : (int) $row['station_id'],
            $row['printer_id'] === null ? null : (int) $row['printer_id'],
            PrintJobType::from((string) $row['job_type']),
            PrintJobStatus::from((string) $row['status']),
            (string) $row['token_snapshot'],
            $row['station_code_snapshot'] === null ? null : (string) $row['station_code_snapshot'],
            $row['station_name_snapshot'] === null ? null : (string) $row['station_name_snapshot'],
            $row['printer_name_snapshot'] === null ? null : (string) $row['printer_name_snapshot'],
            $adapter,
            $row['configuration_ref_snapshot'] === null ? null : (string) $row['configuration_ref_snapshot'],
            (int) $row['paper_width_mm'],
            (bool) $row['cut_after_print'],
            (string) $row['payload_text'],
            (int) $row['attempt_count'],
        );
    }

    private function beginClaimTransaction(): void
    {
        if ($this->pdo->inTransaction() || $this->sqliteClaimTransactionOpen) {
            throw new RuntimeException('Print job operations cannot join another transaction.');
        }
        if ($this->driver() === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE TRANSACTION');
            $this->sqliteClaimTransactionOpen = true;
            return;
        }
        $this->pdo->beginTransaction();
        if ($this->authorization !== null) {
            try {
                ($this->authorization)();
            } catch (Throwable $error) {
                $this->rollbackClaimTransaction();
                throw $error;
            }
        }
    }

    private function commitClaimTransaction(): void
    {
        if ($this->sqliteClaimTransactionOpen) {
            $this->pdo->exec('COMMIT');
            $this->sqliteClaimTransactionOpen = false;
            return;
        }
        $this->pdo->commit();
    }

    private function rollbackClaimTransaction(): void
    {
        if ($this->sqliteClaimTransactionOpen) {
            $this->pdo->exec('ROLLBACK');
            $this->sqliteClaimTransactionOpen = false;
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function adapterFor(PrintJob $job): PrintAdapter
    {
        if ($this->virtualOnly) {
            if ($job->adapterType !== PrinterAdapterType::Virtual) {
                throw new PrintAdapterFailure(PrintErrorCategory::NoPrinterConfigured, 'Only virtual delivery is authorized.');
            }
            return $this->virtualAdapter;
        }
        if (Environment::get('APP_ENV', 'local') === 'demo') {
            if (Environment::get('PRINT_DRIVER') !== 'virtual' || $job->adapterType !== PrinterAdapterType::Virtual) {
                throw new PrintAdapterFailure(
                    PrintErrorCategory::NoPrinterConfigured,
                    'Hosted demo printing permits virtual printers only.',
                );
            }
            return $this->virtualAdapter;
        }

        return match ($job->adapterType) {
            PrinterAdapterType::Virtual => $this->virtualAdapter,
            PrinterAdapterType::WindowsSpooler => $this->windowsAdapter ?? new WindowsPrinterAdapter(),
            PrinterAdapterType::Gateway, null => throw new PrintAdapterFailure(PrintErrorCategory::NoPrinterConfigured, 'No direct printer is configured for this job.'),
        };
    }
}
