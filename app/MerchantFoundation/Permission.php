<?php
declare(strict_types=1);

namespace Kios\MerchantFoundation;

enum Permission: string
{
    case ManagePlatform = 'manage_platform';
    case ManageMerchant = 'manage_merchant';
    case ManageOutlet = 'manage_outlet';
    case ManageStaff = 'manage_staff';
    case ReadOrders = 'read_orders';
    case RecordCash = 'record_cash';
    case PrepareOrders = 'prepare_orders';
}