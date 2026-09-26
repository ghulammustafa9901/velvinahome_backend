<?php

declare(strict_types=1);

namespace Modules\Support\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * Gives a model a public ULID alongside its internal auto-increment key (ADR-0008).
 *
 * The integer key stays internal — narrow indexes, fast joins — while the ULID is the only
 * identifier that ever appears in a URL, an API payload or an export. Route binding is
 * switched to the ULID here rather than at each route, so forgetting to do it per route is
 * not a way to leak an internal id.
 *
 * @phpstan-require-extends Model
 */
trait HasUlid
{
    public static function bootHasUlid(): void
    {
        // Typed as self, not Model: inside a trait self resolves to the using class, which is
        // the only type that actually has getUlidColumn().
        static::creating(function (self $model): void {
            $column = $model->getUlidColumn();

            if (blank($model->getAttribute($column))) {
                $model->setAttribute($column, self::generateUlid());
            }
        });
    }

    public function getUlidColumn(): string
    {
        return 'ulid';
    }

    /**
     * Public identifiers in URLs are ULIDs, never the internal key.
     */
    public function getRouteKeyName(): string
    {
        return $this->getUlidColumn();
    }

    /**
     * @param  Builder<covariant Model>|Relation<covariant Model, covariant Model, mixed>  $query
     * @return Builder<covariant Model>|Relation<covariant Model, covariant Model, mixed>
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query->where($field ?? $this->getUlidColumn(), $value);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereUlid(Builder $query, string $ulid): Builder
    {
        return $query->where($this->getUlidColumn(), $ulid);
    }

    public static function generateUlid(): string
    {
        return (string) Str::ulid();
    }
}
