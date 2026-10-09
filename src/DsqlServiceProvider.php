<?php

namespace JGamboa\LaravelAuroraDsql;

use Aws\AuroraDsql\PdoPgsql\AuroraDsql;
use Aws\AuroraDsql\PdoPgsql\DsqlConfig;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use JGamboa\LaravelAuroraDsql\Database\DsqlConnection;

/**
 * Registers the "dsql" database driver.
 *
 * The PDO is created by the official AWS connector (IAM token + SSL
 * verify-full) and wrapped in a DsqlConnection (DSQL-compatible grammar).
 */
class DsqlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving('db', fn (DatabaseManager $db) => $db->extend('dsql', function (array $config, string $name) {
            // The connector uses sslmode=verify-full without sslrootcert, so libpq
            // looks for ~/.postgresql/root.crt. 'system' (libpq 17+) uses the OS
            // trust store; with an older libpq (e.g. on Lambda) use a CA bundle path.
            if (getenv('PGSSLROOTCERT') === false) {
                putenv('PGSSLROOTCERT='.($config['sslrootcert'] ?? 'system'));
            }

            // The PHP SDK only resolves the profile reliably through AWS_PROFILE
            // (the connector's profile option is not enough with SSO). Leave it
            // empty on Lambda/EC2/ECS so the execution role is used.
            if (! empty($config['profile'])) {
                putenv('AWS_PROFILE='.$config['profile']);
            }

            $pdo = AuroraDsql::connect(new DsqlConfig(
                host: $config['host'],
                region: $config['region'] ?? null,
                user: $config['username'] ?? 'admin',
                database: $config['database'] ?? 'postgres',
                profile: $config['profile'] ?? null,
                occMaxRetries: $config['occ_max_retries'] ?? 3,
            ));

            // Without 'name', Connection::getName() returns null and Eloquent
            // (Builder::newModelInstance -> setConnection(null)) falls back to
            // the default connection on Model::create().
            $config['name'] = $name;

            return new DsqlConnection(
                $pdo,
                $config['database'] ?? 'postgres',
                $config['prefix'] ?? '',
                $config
            );
        }));
    }
}
