<?php

declare(strict_types=1);

namespace Modules\Reviews\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Modules\Reviews\Models\Review;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

final class ModerateReview implements Action
{
    use ChecksPermission;

    /**
     * @param  'approved'|'rejected'  $status
     */
    public function handle(Review $review, string $status, ?Authorizable $actor = null): Review
    {
        $this->authorizePermission($actor, 'reviews.moderate');

        $moderator = $actor instanceof Model ? $actor->getAttribute('ulid') : null;

        $review->forceFill([
            'status' => $status,
            'moderated_at' => now(),
            'moderator_ulid' => is_string($moderator) ? $moderator : null,
        ])->save();

        return $review;
    }
}
