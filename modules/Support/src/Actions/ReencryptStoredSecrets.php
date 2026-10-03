<?php

declare(strict_types=1);

namespace Modules\Support\Actions;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Encryption\Encrypter;
use Modules\Support\Contracts\Action;
use Modules\Support\Contracts\ReencryptsStoredSecrets;
use Modules\Support\Events\StoredSecretsReencrypted;
use Modules\Support\Modules\ModuleRegistry;

/**
 * Re-encrypt every declared secret column with the current APP_KEY. Values still under a key
 * from APP_PREVIOUS_KEYS are decrypted with it and written back under the current key; values
 * already under the current key are left alone, so a re-run changes nothing
 * (docs/runbooks/rotate-secrets.md).
 *
 * A value no configured key can decrypt is counted as unreadable and left untouched.
 */
final class ReencryptStoredSecrets implements Action, ReencryptsStoredSecrets
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ModuleRegistry $modules,
        private readonly Encrypter $encrypter,
    ) {}

    /**
     * @return array<string, array{reencrypted: int, current: int, unreadable: int}> table.column => counts
     */
    public function handle(): array
    {
        $connection = (string) config('database.default');
        $current = new Encrypter($this->encrypter->getKey(), (string) config('app.cipher'));
        $report = [];

        foreach ($this->modules->encryptedColumns() as $column) {
            $counts = ['reencrypted' => 0, 'current' => 0, 'unreadable' => 0];
            $query = $this->db->connection($connection)->table($column->table)->whereNotNull($column->column);

            foreach ($column->where as $name => $value) {
                $query->where($name, $value);
            }

            $query->select(['id', $column->column])->orderBy('id')->chunkById(500, function ($rows) use ($column, $connection, $current, &$counts): void {
                foreach ($rows as $row) {
                    $raw = (string) $row->{$column->column};
                    $ciphertext = $column->jsonEncoded ? json_decode($raw, true) : $raw;

                    if (! is_string($ciphertext) || $ciphertext === '') {
                        continue;
                    }

                    try {
                        $current->decryptString($ciphertext);
                        $counts['current']++;

                        continue;
                    } catch (DecryptException) {
                        // Not under the current key: try the previous ones below.
                    }

                    try {
                        $plain = $this->encrypter->decryptString($ciphertext);
                    } catch (DecryptException) {
                        $counts['unreadable']++;

                        continue;
                    }

                    $fresh = $current->encryptString($plain);
                    $this->db->connection($connection)->table($column->table)->where('id', $row->id)->update([
                        $column->column => $column->jsonEncoded ? json_encode($fresh, JSON_THROW_ON_ERROR) : $fresh,
                    ]);
                    $counts['reencrypted']++;
                }
            });

            $report[$column->key()] = $counts;
        }

        if (array_sum(array_column($report, 'reencrypted')) > 0) {
            StoredSecretsReencrypted::dispatch();
        }

        return $report;
    }
}
