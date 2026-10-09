<?php

namespace JGamboa\LaravelAuroraDsql\Migrations;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Base opcional para migraciones DSQL escritas con SQL crudo.
 *
 * Con el driver 'dsql' tambien se puede usar el schema builder normal; esta
 * base es para lo que el builder no cubre. Cada statement hace autocommit
 * (DSQL: una DDL por transaccion), asi que si una migracion falla a mitad
 * queda aplicada a medias: escribir up()/down() con IF [NOT] EXISTS.
 */
abstract class DsqlMigration extends Migration
{
    protected $connection = 'dsql';

    public $withinTransaction = false;

    protected function db(): Connection
    {
        return DB::connection($this->connection);
    }

    /** Ejecuta una sentencia DDL (una por llamada; autocommit). */
    protected function ddl(string $sql): void
    {
        $this->db()->statement($sql);
    }

    /**
     * Ejecuta un CREATE [UNIQUE] INDEX ASYNC. DsqlConnection espera a que el
     * job de construccion termine salvo 'wait_for_indexes' => false.
     */
    protected function createIndexAsync(string $sql): void
    {
        $this->db()->statement($sql);
    }
}
