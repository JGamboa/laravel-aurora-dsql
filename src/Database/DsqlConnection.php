<?php

namespace JGamboa\LaravelAuroraDsql\Database;

use Illuminate\Database\PostgresConnection;

/**
 * Conexion para Aurora DSQL: PostgresConnection con el grammar de schema DSQL.
 * El PDO lo crea DsqlServiceProvider con el conector oficial (token IAM + SSL).
 */
class DsqlConnection extends PostgresConnection
{
    public function getDriverName()
    {
        // Internamente se comporta como Postgres (query grammar, processor).
        return 'pgsql';
    }

    protected function getDefaultSchemaGrammar()
    {
        return new DsqlSchemaGrammar($this);
    }

    /**
     * CREATE [UNIQUE] INDEX ASYNC devuelve un job_id y el indice no es valido
     * hasta que el job termina. Por defecto se espera (CALL sys.wait_for_job)
     * para que la migracion siguiente pueda depender del indice; se desactiva
     * con 'wait_for_indexes' => false en la config de la conexion.
     */
    public function statement($query, $bindings = [])
    {
        if ($this->pretending() || ! preg_match('/^\s*create\s+(unique\s+)?index\s+async\b/i', $query)) {
            return parent::statement($query, $bindings);
        }

        $jobId = $this->selectOne($query, $bindings, false)?->job_id;

        if ($jobId && $this->getConfig('wait_for_indexes') !== false) {
            parent::statement('call sys.wait_for_job(?)', [$jobId]);
        }

        return true;
    }
}
