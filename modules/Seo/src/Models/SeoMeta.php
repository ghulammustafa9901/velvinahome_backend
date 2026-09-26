<?php

declare(strict_types=1);

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $subject_type
 * @property string $subject_ulid
 * @property string|null $title
 * @property string|null $description
 * @property string|null $canonical_url
 * @property string|null $robots
 * @property string|null $og_image_url
 */
final class SeoMeta extends Model
{
    protected $table = 'seo_meta';

    protected $guarded = ['id'];
}
