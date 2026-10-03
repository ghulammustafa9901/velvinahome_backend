<?php

declare(strict_types=1);

namespace Modules\Identity\Enums;

use Modules\Identity\Enums\StaffPermission as P;

/**
 * The default staff roles (spec §6.2), and the permission matrix — the documented, reviewable
 * source of truth for who may do what.
 */
enum StaffRole: string
{
    case Owner = 'Owner';
    case Manager = 'Manager';
    case OrderHandler = 'Order Handler';
    case InventoryStaff = 'Inventory Staff';
    case ContentEditor = 'Content Editor';
    case Accountant = 'Accountant';

    /**
     * @return list<P>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => P::cases(),
            self::Manager => array_values(array_filter(
                P::cases(),
                fn (P $permission): bool => ! in_array($permission, [P::RolesManage, P::SettingsManage, P::SystemMonitor], true),
            )),
            self::OrderHandler => [
                P::OrdersView, P::OrdersManage, P::OrdersConfirmCod,
                P::CustomersView, P::ProductsView, P::InventoryView,
            ],
            self::InventoryStaff => [
                P::ProductsView, P::InventoryView, P::InventoryAdjust, P::InventoryReceive,
                P::InventoryTransfer, P::OrdersView,
            ],
            self::ContentEditor => [
                P::ProductsView, P::ProductsManage, P::ContentManage, P::SeoManage, P::ReviewsModerate,
            ],
            self::Accountant => [
                P::OrdersView, P::OrdersRefund, P::ReportsView, P::CustomersView, P::InventoryView,
                P::ActivityView,
            ],
        };
    }
}
