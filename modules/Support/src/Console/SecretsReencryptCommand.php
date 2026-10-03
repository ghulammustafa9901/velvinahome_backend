<?php

declare(strict_types=1);

namespace Modules\Support\Console;

use Illuminate\Console\Command;
use Modules\Support\Contracts\ReencryptsStoredSecrets;
use Modules\Support\Security\EncryptedColumn;

/**
 * Step 2 of an APP_KEY rotation (docs/runbooks/rotate-secrets.md): with the new key in APP_KEY
 * and the old one in APP_PREVIOUS_KEYS, move every stored secret to the new key. Exits
 * non-zero while anything is unreadable, because then the old key cannot be retired yet.
 */
final class SecretsReencryptCommand extends Command
{
    protected $signature = 'secrets:reencrypt';

    protected $description = 'Re-encrypt every stored secret with the current APP_KEY';

    public function handle(ReencryptsStoredSecrets $reencrypt): int
    {
        $columns = $reencrypt->handle(EncryptedColumn::TENANT, (string) config('database.default'));
        $unreadable = 0;

        foreach ($columns as $column => $counts) {
            $unreadable += $counts['unreadable'];

            $this->components->twoColumnDetail($column, sprintf(
                '%d re-encrypted, %d already current%s',
                $counts['reencrypted'],
                $counts['current'],
                $counts['unreadable'] > 0 ? ", <fg=red>{$counts['unreadable']} no configured key can decrypt</>" : '',
            ));
        }

        if ($unreadable > 0) {
            $this->components->error('Not every secret is under the new key. Keep the old key in APP_PREVIOUS_KEYS.');

            return self::FAILURE;
        }

        $this->components->info('Every stored secret is under the current APP_KEY. The previous key can be retired.');

        return self::SUCCESS;
    }
}
