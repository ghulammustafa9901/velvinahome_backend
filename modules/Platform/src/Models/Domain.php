<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Enums\DomainKind;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $hostname
 * @property DomainKind $kind
 * @property bool $is_primary
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 */
final class Domain extends LandlordModel
{
    protected function casts(): array
    {
        return [
            'kind' => DomainKind::class,
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    protected function setHostnameAttribute(string $value): void
    {
        $this->attributes['hostname'] = strtolower(trim($value));
    }
}
