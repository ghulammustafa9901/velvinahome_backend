<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Modules\Support\Concerns\HasUlid;

/**
 * Minimal model used to test the HasUlid trait in isolation.
 *
 * Real models arrive with their modules; this fixture exists so the trait's behaviour is
 * verified on its own rather than incidentally through a domain model's tests.
 *
 * @property int $id
 * @property string $ulid
 * @property string|null $name
 */
final class UlidFixtureModel extends Model
{
    use HasUlid;

    protected $table = 'ulid_fixtures';

    protected $guarded = [];

    public $timestamps = false;
}
