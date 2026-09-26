<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Modules\Support\Contracts\Action;
use Modules\Support\Modules\ModuleRegistry;

/**
 * Runs landlord migrations as the landlord migration role, then re-applies the append-only
 * lockdown on the landlord's audit tables.
 *
 * The application's own landlord role has no DDL, so a plain `php artisan migrate` on the
 * default connection fails by design; this Action is the only way the landlord schema moves.
 */
final class MigrateLandlord implements Action
{
    private const CONNECTION = 'landlord_migrator';

    /**
     * Landlord ledgers. `impersonation_sessions` keeps UPDATE because sessions are closed by
     * setting `ended_at`, but can never be deleted.
     *
     * @var array<string, string>
     */
    private const LOCKDOWN = [
        'platform_activity_log' => 'UPDATE, DELETE, TRUNCATE',
        'platform_login_audits' => 'UPDATE, DELETE, TRUNCATE',
        'impersonation_sessions' => 'DELETE, TRUNCATE',
    ];

    public function __construct(
        private readonly Migrator $migrator,
        private readonly DatabaseManager $db,
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * @return list<string> the migrations applied by this run
     */
    public function handle(): array
    {
        $applied = [];

        $this->migrator->usingConnection(self::CONNECTION, function () use (&$applied): void {
            if (! $this->migrator->repositoryExists()) {
                $this->migrator->getRepository()->createRepository();
            }

            $applied = $this->migrator->run($this->modules->landlordMigrationPaths());
        });

        $connection = $this->db->connection(self::CONNECTION);
        $appRole = (string) config('database.connections.landlord.username');

        foreach (self::LOCKDOWN as $table => $privileges) {
            $connection->statement(sprintf('REVOKE %s ON TABLE "%s" FROM "%s"', $privileges, $table, $appRole));
        }

        /** @var list<string> $applied */
        return array_values(array_map(fn (string $path): string => basename($path, '.php'), $applied));
    }
}
