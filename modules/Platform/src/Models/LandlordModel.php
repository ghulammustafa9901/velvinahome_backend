<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Every landlord model is pinned to the landlord connection explicitly. Inside tenant
 * context the default connection is the tenant's, and a landlord model silently following
 * it would read from — or write platform data into — a brand's database.
 */
abstract class LandlordModel extends Model
{
    protected $connection = 'landlord';

    protected $guarded = ['id'];
}
