<?php

namespace JGamboa\LaravelAuroraDsql\Database;

use Illuminate\Database\PostgresConnection;

/**
 * Aurora DSQL connection: a PostgresConnection with the DSQL schema grammar.
 * The PDO is created by DsqlServiceProvider using the official connector
 * (IAM token + SSL).
 */
class DsqlConnection extends PostgresConnection
{
    public function getDriverName()
    {
        // Behaves as Postgres internally (query grammar, processor).
        return 'pgsql';
    }

    protected function getDefaultSchemaGrammar()
    {
        return new DsqlSchemaGrammar($this);
    }

    /**
     * CREATE [UNIQUE] INDEX ASYNC returns a job_id and the index is not valid
     * until the job finishes. By default we wait for it (CALL sys.wait_for_job)
     * so later migrations can rely on the index; disable it with
     * 'wait_for_indexes' => false in the connection config.
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
