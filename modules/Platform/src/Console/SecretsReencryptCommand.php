<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Actions\ReencryptAllSecrets;

/**
 * Step 2 of an APP_KEY rotation: with the new key in APP_KEY and the old one in
 * APP_PREVIOUS_KEYS, move every stored secret to the new key. Exits non-zero while anything
 * failed or is unreadable, because then the old key cannot be retired yet.
 */
final class SecretsReencryptCommand extends Command
{
    protected $signature = 'secrets:reencrypt';

    protected $description = 'Re-encrypt every stored secret (landlord and all tenants) with the current APP_KEY';

    public function handle(ReencryptAllSecrets $reencrypt): int
    {
        $problems = 0;

        foreach ($reencrypt->handle() as $database => $columns) {
            if (is_string($columns)) {
                $problems++;
                $this->components->twoColumnDetail($database, "<fg=red>{$columns}</>");

                continue;
            }

            $moved = array_sum(array_column($columns, 'reencrypted'));
            $current = array_sum(array_column($columns, 'current'));
            $unreadable = array_sum(array_column($columns, 'unreadable'));
            $problems += $unreadable;

            $this->components->twoColumnDetail($database, sprintf(
                '%d re-encrypted, %d already current%s',
                $moved,
                $current,
                $unreadable > 0 ? ", <fg=red>{$unreadable} unreadable</>" : '',
            ));

            foreach ($columns as $column => $counts) {
                if ($counts['unreadable'] > 0) {
                    $this->line("    <fg=red>{$column}: {$counts['unreadable']} value(s) no configured key can decrypt</>");
                }
            }
        }

        if ($problems > 0) {
            $this->components->error('Not every secret is under the new key. Keep the old key in APP_PREVIOUS_KEYS.');

            return self::FAILURE;
        }

        $this->components->info('Every stored secret is under the current APP_KEY. The previous key can be retired.');

        return self::SUCCESS;
    }
}
