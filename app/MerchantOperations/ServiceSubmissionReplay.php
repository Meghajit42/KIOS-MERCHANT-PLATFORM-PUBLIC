<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
final class ServiceSubmissionReplay extends \RuntimeException
{
    public function __construct(public readonly array $result){parent::__construct('Exact service submission replay.');}
}
