<?php

declare(strict_types=1);

namespace Modules\Identity\Enums;

/**
 * Every staff permission, declared once. Modules gate on these names inside their Actions;
 * roles are only bundles of them (spec §6.2).
 *
 * Permissions for modules that arrive in later phases are declared now so the role matrix
 * is reviewed once, not re-negotiated per phase.
 */
enum StaffPermission: string
{
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case RolesManage = 'roles.manage';
    case SettingsView = 'settings.view';
    case SettingsManage = 'settings.manage';
    case ActivityView = 'activity.view';

    case ProductsView = 'products.view';
    case ProductsManage = 'products.manage';
    case ProductsPublish = 'products.publish';

    case InventoryView = 'inventory.view';
    case InventoryAdjust = 'inventory.adjust';
    case InventoryReceive = 'inventory.receive';
    case InventoryTransfer = 'inventory.transfer';

    case OrdersView = 'orders.view';
    case OrdersManage = 'orders.manage';
    case OrdersConfirmCod = 'orders.confirm_cod';
    case OrdersRefund = 'orders.refund';

    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';

    case PromotionsManage = 'promotions.manage';
    case ContentManage = 'content.manage';
    case SeoManage = 'seo.manage';
    case ReviewsModerate = 'reviews.moderate';
    case ReportsView = 'reports.view';
}
