<?php

declare(strict_types=1);

namespace Modules\Platform\Enums;

/**
 * Which surface a hostname serves. The middleware stack is chosen by kind, so an admin
 * panel can never be reached on a storefront host and vice versa (tenancy.md §2).
 */
enum DomainKind: string
{
    case Storefront = 'storefront';
    case Admin = 'admin';
    case Api = 'api';
}
