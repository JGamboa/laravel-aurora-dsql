<?php

namespace JGamboa\LaravelAuroraDsql\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;
use RuntimeException;

/**
 * Schema grammar for Aurora DSQL.
 *
 * PostgreSQL differences it handles (verified against a live cluster):
 *  - Primary and foreign keys can only be declared inline in CREATE TABLE
 *    (no ALTER TABLE ADD PRIMARY KEY / ADD CONSTRAINT): they are emitted as
 *    table constraints in compileCreate() and the separate ALTERs are dropped.
 *  - Only CREATE [UNIQUE] INDEX ASYNC is supported.
 *  - There is no SERIAL; IDENTITY columns must be bigint with CACHE >= 65536.
 *  - One DDL statement per transaction: no schema transactions.
 *  - pg_total_relation_size is not supported (table listing).
 */
class DsqlSchemaGrammar extends PostgresGrammar
{
    /**
     * DSQL allows a single DDL statement per transaction and cannot mix DDL
     * with DML, so the Migrator must not wrap migrations in a transaction.
     */
    protected $transactions = false;

    /**
     * CREATE TABLE with the blueprint's primary and foreign keys as inline
     * table constraints.
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command)
    {
        $definitions = $this->getColumns($blueprint);

        $commands = $blueprint->getCommands();

        foreach ($commands as $c) {
            if ($c->name === 'primary') {
                $definitions[] = 'primary key ('.$this->columnize($c->columns).')';
            }
        }

        foreach ($commands as $c) {
            if ($c->name === 'foreign') {
                $definitions[] = $this->compileInlineForeign($blueprint, $c);
            }
        }

        return sprintf('create table %s (%s)',
            $this->wrapTable($blueprint),
            implode(', ', $definitions)
        );
    }

    /** The primary key is inlined by compileCreate(); DSQL cannot add it later. */
    public function compilePrimary(Blueprint $blueprint, Fluent $command)
    {
        if ($blueprint->creating()) {
            return null;
        }

        throw new RuntimeException(
            'Aurora DSQL does not support adding a PRIMARY KEY to an existing table; declare it in Schema::create().'
        );
    }

    /** Foreign keys are inlined by compileCreate(); DSQL cannot add them later. */
    public function compileForeign(Blueprint $blueprint, Fluent $command)
    {
        if ($blueprint->creating()) {
            return null;
        }

        throw new RuntimeException(
            'Aurora DSQL does not support adding a FOREIGN KEY to an existing table; declare it in Schema::create().'
        );
    }

    /**
     * "constraint x foreign key (...) references ..." derived from the ALTER
     * produced by PostgresGrammar (reuses on delete/update, deferrable, etc.).
     */
    protected function compileInlineForeign(Blueprint $blueprint, Fluent $command): string
    {
        $sql = parent::compileForeign($blueprint, $command);
        $prefix = 'alter table '.$this->wrapTable($blueprint).' add ';

        return substr($sql, strlen($prefix));
    }

    /** Plain/composite index -> CREATE INDEX ASYNC */
    public function compileIndex(Blueprint $blueprint, Fluent $command)
    {
        return sprintf('create index async %s on %s%s (%s)',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            $command->algorithm ? ' using '.$command->algorithm : '',
            $this->columnize($command->columns)
        );
    }

    /** Unique (incl. composite) -> CREATE UNIQUE INDEX ASYNC */
    public function compileUnique(Blueprint $blueprint, Fluent $command)
    {
        return sprintf('create unique index async %s on %s%s (%s)',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            $command->algorithm ? ' using '.$command->algorithm : '',
            $this->columnize($command->columns)
        );
    }

    /**
     * DSQL does not support pg_total_relation_size (and pg_relation_size
     * returns 0), so tables are listed without a size. Used by
     * Schema::getTables(), db:show, db:wipe and migrate:fresh.
     */
    public function compileTables($schema)
    {
        return 'select c.relname as name, n.nspname as schema, null as size, '
            ."obj_description(c.oid, 'pg_class') as comment from pg_class c, pg_namespace n "
            ."where c.relkind in ('r', 'p') and n.oid = c.relnamespace and "
            .$this->compileSchemaWhereClause($schema, 'n.nspname')
            .' order by n.nspname, c.relname';
    }

    /*
     * Auto-increment: DSQL has no 'serial'. Every autoIncrement column becomes
     * a bigint IDENTITY with CACHE 65536 ($table->id(), increments(), and
     * Laravel's own 'migrations' table). Its primary key is already inline:
     * PostgresGrammar::modifyIncrement() appends ' primary key' to the column.
     */

    protected function typeInteger(Fluent $column)
    {
        return $this->maybeIdentity($column, 'integer');
    }

    protected function typeBigInteger(Fluent $column)
    {
        return $this->maybeIdentity($column, 'bigint');
    }

    protected function typeMediumInteger(Fluent $column)
    {
        return $this->maybeIdentity($column, 'integer');
    }

    protected function typeSmallInteger(Fluent $column)
    {
        return $this->maybeIdentity($column, 'smallint');
    }

    protected function typeTinyInteger(Fluent $column)
    {
        return $this->maybeIdentity($column, 'smallint');
    }

    protected function maybeIdentity(Fluent $column, string $plainType): string
    {
        if ($column->autoIncrement && ! $column->change) {
            return 'bigint generated by default as identity (cache 65536)';
        }

        return $plainType;
    }
}
