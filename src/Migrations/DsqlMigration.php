<?php

namespace JGamboa\LaravelAuroraDsql\Migrations;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Optional base class for DSQL migrations written in raw SQL.
 *
 * The regular schema builder works with the 'dsql' driver; use this for what
 * the builder does not cover. Every statement autocommits (DSQL allows one DDL
 * per transaction), so a migration that fails midway stays partially applied:
 * write up()/down() with IF [NOT] EXISTS.
 */
abstract class DsqlMigration extends Migration
{
    protected $connection = 'dsql';

    public $withinTransaction = false;

    protected function db(): Connection
    {
        return DB::connection($this->connection);
    }

    /** Runs a single DDL statement (one per call; autocommit). */
    protected function ddl(string $sql): void
    {
        $this->db()->statement($sql);
    }

    /**
     * Runs a CREATE [UNIQUE] INDEX ASYNC. DsqlConnection waits for the build
     * job to finish unless 'wait_for_indexes' => false.
     */
    protected function createIndexAsync(string $sql): void
    {
        $this->db()->statement($sql);
    }
}
