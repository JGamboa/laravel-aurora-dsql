<?php

namespace JGamboa\LaravelAuroraDsql;

use Aws\AuroraDsql\PdoPgsql\AuroraDsql;
use Aws\AuroraDsql\PdoPgsql\DsqlConfig;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use JGamboa\LaravelAuroraDsql\Database\DsqlConnection;

/**
 * Registra el driver de base "dsql".
 *
 * El PDO lo genera el conector oficial de AWS (token IAM + SSL verify-full) y
 * se envuelve en una DsqlConnection (grammar compatible con DSQL).
 */
class DsqlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving('db', fn (DatabaseManager $db) => $db->extend('dsql', function (array $config, string $name) {
            // El conector usa sslmode=verify-full sin sslrootcert y libpq busca
            // ~/.postgresql/root.crt. 'system' (libpq 17+) usa los root del SO;
            // si libpq es mas vieja (p.ej. en Lambda) usar la ruta a un CA bundle.
            if (getenv('PGSSLROOTCERT') === false) {
                putenv('PGSSLROOTCERT='.($config['sslrootcert'] ?? 'system'));
            }

            // El SDK de PHP resuelve el perfil de forma confiable solo via
            // AWS_PROFILE (el parametro profile del conector no alcanza con SSO).
            // En Lambda dejarlo vacio: se usa el rol de ejecucion.
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

            // Sin 'name', Connection::getName() devuelve null y Eloquent
            // (Builder::newModelInstance -> setConnection(null)) cae a la
            // conexion por defecto al hacer Model::create().
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
