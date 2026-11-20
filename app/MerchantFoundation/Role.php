<?php
declare(strict_types=1);

namespace Kios\MerchantFoundation;

enum Role: string
{
    case PlatformAdministrator = 'platform_administrator';
    case MerchantOwner = 'merchant_owner';
    case OutletAdministrator = 'outlet_administrator';
    case Cashier = 'cashier';
    case Kitchen = 'kitchen';

    public function allows(Permission $permission): bool
    {
        return match ($this) {
            self::PlatformAdministrator => $permission === Permission::ManagePlatform,
            self::MerchantOwner => in_array($permission, [
                Permission::ManageMerchant, Permission::ManageOutlet, Permission::ManageStaff,
                Permission::ReadOrders, Permission::RecordCash, Permission::PrepareOrders,
            ], true),
            self::OutletAdministrator => in_array($permission, [
                Permission::ManageOutlet, Permission::ManageStaff, Permission::ReadOrders,
                Permission::RecordCash, Permission::PrepareOrders,
            ], true),
            self::Cashier => in_array($permission, [Permission::ReadOrders, Permission::RecordCash], true),
            self::Kitchen => in_array($permission, [Permission::ReadOrders, Permission::PrepareOrders], true),
        };
    }
}