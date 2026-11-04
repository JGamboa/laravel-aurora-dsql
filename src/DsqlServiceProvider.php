<?php

namespace Multiapi\LaravelAuroraDsql;

use Aws\AuroraDsql\PdoPgsql\AuroraDsql;
use Aws\AuroraDsql\PdoPgsql\DsqlConfig;
use Illuminate\Database\Concerns\ParsesSearchPath;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Multiapi\LaravelAuroraDsql\Database\DsqlConnection;
use PDO;

/**
 * Registers the "dsql" database driver.
 *
 * The PDO is created by the official AWS connector (IAM token + SSL
 * verify-full) and wrapped in a DsqlConnection (DSQL-compatible grammar).
 */
class DsqlServiceProvider extends ServiceProvider
{
    use ParsesSearchPath;

    public function register(): void
    {
        $this->callAfterResolving('db', fn (DatabaseManager $db) => $db->extend('dsql', function (array $config, string $name) {
            // The connector uses sslmode=verify-full without sslrootcert, so libpq
            // looks for ~/.postgresql/root.crt. 'system' (libpq 17+) uses the OS
            // trust store; with an older libpq (e.g. on Lambda) use a CA bundle path.
            // putenv() does not reach libpq inside FrankenPHP workers: there PGSSLROOTCERT must be a real
            // environment variable of the process (see the README).
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
            ), $this->pdoAttributes());

            $this->configureSearchPath($pdo, $config);

            $config = $this->connectionConfig($config, $name);

            return new DsqlConnection(
                $pdo,
                $config['database'] ?? 'postgres',
                $config['prefix'] ?? '',
                $config
            );
        }));
    }

    /**
     * The config the DsqlConnection is built with.
     */
    public function connectionConfig(array $config, string $name): array
    {
        // Without 'name', Connection::getName() returns null and Eloquent
        // (Builder::newModelInstance -> setConnection(null)) falls back to
        // the default connection on Model::create().
        $config['name'] = $name;

        // Record the PDO attributes in 'options' too: Laravel's
        // PostgresConnection reads ATTR_EMULATE_PREPARES from there.
        $config['options'] = $this->pdoAttributes() + ($config['options'] ?? []);

        return $config;
    }

    /**
     * PDO attributes for every DSQL connection.
     *
     * Emulated prepares: pdo_pgsql built against libpq 17+ closes each
     * server-side prepared statement with a protocol Close message, which
     * Aurora DSQL rejects. Inside a transaction that error aborts it, so the
     * second statement fails with SQLSTATE 25P02 ("current transaction is
     * aborted"). With emulated prepares no server-side statements are created.
     * Values are then sent as SQL literals, so DsqlConnection binds booleans
     * as 'true'/'false' instead of 1/0.
     *
     * @return array<int, mixed>
     */
    public function pdoAttributes(): array
    {
        return [PDO::ATTR_EMULATE_PREPARES => true];
    }

    /**
     * Apply the configured 'search_path' (or 'schema') to the session, as
     * Laravel's PostgresConnector does. The AWS connector does not, so without
     * this the role's default search_path would be used while the schema
     * builder (hasTable, migrations) looks in the configured one.
     */
    public function configureSearchPath(PDO $pdo, array $config): void
    {
        if (! isset($config['search_path']) && ! isset($config['schema'])) {
            return;
        }

        $searchPath = $this->parseSearchPath($config['search_path'] ?? $config['schema']);

        $pdo->prepare('set search_path to "'.implode('", "', $searchPath).'"')->execute();
    }
}
