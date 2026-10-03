<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Catalog\Models\SearchSynonym;
use Modules\Catalog\Support\TypesenseSynonyms;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Creates, edits or (with an empty term list) deletes a synonym rule, then pushes the whole
 * set to the store's Typesense synonym set once the transaction commits.
 */
final class SaveSearchSynonym implements Action
{
    use ChecksPermission;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TypesenseSynonyms $typesense,
    ) {}

    /**
     * @param  list<string>  $terms
     */
    public function handle(?SearchSynonym $synonym, array $terms, ?string $root = null, ?Authorizable $actor = null): ?SearchSynonym
    {
        $this->authorizePermission($actor, 'seo.manage');

        $terms = array_values(array_unique(array_filter(array_map(fn (string $term): string => mb_strtolower(trim($term)), $terms))));
        $root = $root === null || trim($root) === '' ? null : mb_strtolower(trim($root));

        if ($terms !== [] && count($terms) < ($root === null ? 2 : 1)) {
            throw ValidationException::withMessages(['terms' => __('A synonym group needs at least two terms, or a root and one term.')]);
        }

        $result = $this->db->connection()->transaction(function () use ($synonym, $terms, $root): ?SearchSynonym {
            if ($terms === []) {
                $synonym?->delete();

                return null;
            }

            $synonym ??= new SearchSynonym;
            $synonym->fill(['terms' => $terms, 'root' => $root])->save();

            return $synonym;
        });

        // Runs immediately when there is no outer transaction, after its commit otherwise.
        $this->db->connection()->afterCommit(fn () => $this->typesense->sync());

        return $result;
    }
}
