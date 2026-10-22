<?php

namespace JGamboa\LaravelAuroraDsql\Database;

use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * Postgres query grammar without savepoints: Aurora DSQL rejects SAVEPOINT
 * ("unsupported transaction statement", SQLSTATE 0A000). Laravel then runs a
 * nested DB::transaction() inside the outer transaction instead of a
 * savepoint, so an exception caught inside the nested closure does not undo
 * that closure's writes; one that reaches the outer transaction rolls back all.
 */
class DsqlQueryGrammar extends PostgresGrammar
{
    public function supportsSavepoints()
    {
        return false;
    }
}
