<?php

declare(strict_types=1);

namespace Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;
use Modules\Support\ValueObjects\JsonObject;

/**
 * @property int $id
 * @property string $ulid
 * @property int $content_type_id
 * @property string $slug
 * @property string $title
 * @property array<string, mixed> $values
 * @property string $status
 * @property int $position
 * @property Carbon $updated_at
 * @property-read ContentType $type
 */
final class ContentEntry extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['values' => 'array', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<ContentType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ContentType::class, 'content_type_id');
    }

    /**
     * @return array{id: string, slug: string, title: string, values: JsonObject, position: int, updated_at: string}
     *
     * @scramble-return array{id: string, slug: string, title: string, values: \Modules\Support\ValueObjects\JsonObject, position: int, updated_at: string}
     */
    public function toApi(): array
    {
        return [
            'id' => $this->ulid,
            'slug' => $this->slug,
            'title' => $this->title,
            'values' => new JsonObject($this->values),
            'position' => $this->position,
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  iterable<self>  $entries
     * @return list<array{id: string, slug: string, title: string, values: JsonObject, position: int, updated_at: string}>
     *
     * @scramble-return list<array{id: string, slug: string, title: string, values: \Modules\Support\ValueObjects\JsonObject, position: int, updated_at: string}>
     */
    public static function listToApi(iterable $entries): array
    {
        $list = [];

        foreach ($entries as $entry) {
            $list[] = $entry->toApi();
        }

        return $list;
    }
}
