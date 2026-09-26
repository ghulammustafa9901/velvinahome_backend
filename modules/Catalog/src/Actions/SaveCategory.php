<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Modules\Catalog\Exceptions\CatalogRuleViolation;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Support\Slugs;
use Modules\Seo\Contracts\Seo;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Creates or edits a category, including moving it (with its whole subtree) under another
 * parent. The closure table (ADR-0006) is maintained in the same transaction:
 *
 *  - create: one row to itself (depth 0) plus one per ancestor of the parent;
 *  - move:   drop the links between the subtree and its old ancestors, then link every
 *            subtree node to every new ancestor — two set-based statements at any depth.
 */
final class SaveCategory implements Action
{
    use ChecksPermission;

    public const URL_PREFIX = '/categories/';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Seo $seo,
    ) {}

    public function handle(?Category $category, string $name, ?string $parentUlid = null, ?string $slug = null, ?string $description = null, bool $isVisible = true, int $position = 0, ?Authorizable $actor = null): Category
    {
        $this->authorizePermission($actor, 'products.manage');

        $parent = $parentUlid === null ? null
            : (Category::query()->where('ulid', $parentUlid)->first() ?? throw CatalogRuleViolation::unknown('parent', $parentUlid));

        return $this->db->connection()->transaction(function () use ($category, $name, $parent, $slug, $description, $isVisible, $position): Category {
            $connection = $this->db->connection();

            if ($category === null) {
                $category = Category::query()->create([
                    'parent_id' => $parent?->id,
                    'name' => $name,
                    'slug' => Slugs::unique(Category::query(), $slug ?? $name),
                    'description' => $description,
                    'is_visible' => $isVisible,
                    'position' => $position,
                ]);

                $connection->insert(
                    'INSERT INTO category_closure (ancestor_id, descendant_id, depth)
                     SELECT ancestor_id, ?::bigint, depth + 1 FROM category_closure WHERE descendant_id = ?::bigint
                     UNION ALL SELECT ?::bigint, ?::bigint, 0',
                    [$category->id, $parent?->id ?? 0, $category->id, $category->id],
                );

                return $category;
            }

            $oldSlug = $category->slug;
            $newSlug = $slug === null || $slug === $oldSlug ? $oldSlug : Slugs::unique(Category::query(), $slug, $category->id);

            if (($parent?->id) !== $category->parent_id) {
                $this->move($category, $parent);
            }

            $category->forceFill([
                'parent_id' => $parent?->id,
                'name' => $name,
                'slug' => $newSlug,
                'description' => $description,
                'is_visible' => $isVisible,
                'position' => $position,
            ])->save();

            if ($newSlug !== $oldSlug) {
                $this->seo->recordMove(self::URL_PREFIX.$oldSlug, self::URL_PREFIX.$newSlug);
            }

            return $category;
        });
    }

    private function move(Category $category, ?Category $newParent): void
    {
        $connection = $this->db->connection();

        if ($newParent !== null) {
            $wouldCycle = $connection->table('category_closure')
                ->where('ancestor_id', $category->id)
                ->where('descendant_id', $newParent->id)
                ->exists();

            if ($wouldCycle) {
                throw CatalogRuleViolation::categoryCycle();
            }
        }

        // Detach the subtree from every ancestor outside it.
        $connection->delete(
            'DELETE FROM category_closure
             WHERE descendant_id IN (SELECT descendant_id FROM category_closure WHERE ancestor_id = ?)
               AND ancestor_id NOT IN (SELECT descendant_id FROM category_closure WHERE ancestor_id = ?)',
            [$category->id, $category->id],
        );

        if ($newParent === null) {
            return;
        }

        // Attach every subtree node to every ancestor of the new parent (inclusive).
        $connection->insert(
            'INSERT INTO category_closure (ancestor_id, descendant_id, depth)
             SELECT super.ancestor_id, sub.descendant_id, super.depth + sub.depth + 1
             FROM category_closure super
             CROSS JOIN category_closure sub
             WHERE super.descendant_id = ? AND sub.ancestor_id = ?',
            [$newParent->id, $category->id],
        );
    }
}
