<?php

declare(strict_types=1);

namespace Modules\Content\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Validation\ValidationException;
use Modules\Content\Models\ContentType;
use Modules\Content\Support\ContentFields;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Define a content type. Its key is its public address (/content/{key}) and never changes;
 * fields may be added or edited — entries keep only values of fields that still exist.
 */
final class SaveContentType implements Action
{
    use ChecksPermission;

    /**
     * @param  array<int, mixed>  $fields
     */
    public function handle(?ContentType $type, string $key, string $name, array $fields, ?Authorizable $actor = null): ContentType
    {
        $this->authorizePermission($actor, 'content.manage');

        $key = $type !== null ? $type->key : mb_strtolower(trim($key));

        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/', $key) !== 1) {
            throw ValidationException::withMessages(['key' => __('Use lower-case letters, digits and dashes, starting with a letter.')]);
        }

        if ($type === null && ContentType::query()->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['key' => __('A content type with this key exists.')]);
        }

        if (trim($name) === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => __('Give the type a name of up to 120 characters.')]);
        }

        $type ??= new ContentType(['key' => $key]);
        $type->fill(['name' => trim($name), 'fields' => ContentFields::cleanDefinition($fields)])->save();

        return $type;
    }
}
