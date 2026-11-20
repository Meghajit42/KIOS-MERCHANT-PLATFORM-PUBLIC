<?php
declare(strict_types=1);

namespace Kios\Domain;

use InvalidArgumentException;

final readonly class PrintJob
{
    public function __construct(
        public int $id,
        public int $outletId,
        public int $orderId,
        public ?int $preparationTaskId,
        public ?int $stationId,
        public ?int $printerId,
        public PrintJobType $type,
        public PrintJobStatus $status,
        public string $token,
        public ?string $stationCode,
        public ?string $stationName,
        public ?string $printerName,
        public ?PrinterAdapterType $adapterType,
        public ?string $configurationRef,
        public int $paperWidthMm,
        public bool $cutAfterPrint,
        public string $payloadText,
        public int $attemptCount,
    ) {
        if ($id < 1 || $outletId < 1 || $orderId < 1 || $token === '' || !in_array($paperWidthMm, [58, 80], true)) {
            throw new InvalidArgumentException('The print job snapshot is invalid.');
        }
        if (($type === PrintJobType::StationKot) !== ($preparationTaskId !== null && $stationId !== null && $stationCode !== null)) {
            throw new InvalidArgumentException('The print job task scope does not match its type.');
        }
        if ($printerId === null && $adapterType !== null) {
            throw new InvalidArgumentException('An unassigned print job cannot have an adapter.');
        }
    }
}
