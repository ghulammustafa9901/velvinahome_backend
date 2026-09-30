<?php

declare(strict_types=1);

namespace Modules\Integrations\Support;

use Illuminate\Database\DatabaseManager;
use Modules\Integrations\Contracts\Outbox;
use Modules\Integrations\Contracts\StorefrontCache;

/**
 * Collects the tags of one unit of work (saving a product touches its variants, images and
 * categories) and records a single outbox message once the transaction commits — a rolled-back
 * change purges nothing.
 */
final class BufferedStorefrontCache implements StorefrontCache
{
    public const EVENT = 'storefront.revalidate';

    /** @var array<string, true> */
    private array $pending = [];

    private bool $scheduled = false;

    public function __construct(
        private readonly Outbox $outbox,
        private readonly DatabaseManager $db,
    ) {}

    public function invalidate(array $tags): void
    {
        foreach ($tags as $tag) {
            if ($tag !== '') {
                $this->pending[$tag] = true;
            }
        }

        if ($this->scheduled || $this->pending === []) {
            return;
        }

        $this->scheduled = true;
        $this->db->connection()->afterCommit(fn () => $this->flush());
    }

    private function flush(): void
    {
        $tags = array_keys($this->pending);
        $this->pending = [];
        $this->scheduled = false;

        if ($tags !== []) {
            $this->outbox->record(self::EVENT, ['tags' => $tags]);
        }
    }
}
