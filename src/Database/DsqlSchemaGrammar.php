<?php

namespace JGamboa\LaravelAuroraDsql\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;
use RuntimeException;

/**
 * Grammar de schema para Aurora DSQL.
 *
 * Diferencias con PostgreSQL que resuelve (verificadas contra un cluster):
 *  - PK y FK solo pueden ir inline en el CREATE TABLE (no hay ALTER TABLE ADD
 *    PRIMARY KEY / ADD CONSTRAINT): se agregan como constraints de tabla en
 *    compileCreate() y se suprimen los comandos ALTER separados.
 *  - Solo CREATE [UNIQUE] INDEX ASYNC.
 *  - No existe SERIAL; IDENTITY debe ser bigint y con CACHE >= 65536.
 *  - Una sola sentencia DDL por transaccion: sin transacciones de schema.
 *  - pg_total_relation_size no esta soportado (listado de tablas).
 */
class DsqlSchemaGrammar extends PostgresGrammar
{
    /**
     * DSQL permite una sola sentencia DDL por transaccion y no mezcla DDL con
     * DML, asi que el Migrator no debe envolver las migraciones en una.
     */
    protected $transactions = false;

    /**
     * CREATE TABLE con la PK y las FKs del blueprint como constraints inline.
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

    /** La PK va inline en compileCreate(); DSQL no permite agregarla despues. */
    public function compilePrimary(Blueprint $blueprint, Fluent $command)
    {
        if ($blueprint->creating()) {
            return null;
        }

        throw new RuntimeException(
            'Aurora DSQL no permite agregar una PRIMARY KEY a una tabla existente; definila en Schema::create().'
        );
    }

    /** Las FKs van inline en compileCreate(); DSQL no permite agregarlas despues. */
    public function compileForeign(Blueprint $blueprint, Fluent $command)
    {
        if ($blueprint->creating()) {
            return null;
        }

        throw new RuntimeException(
            'Aurora DSQL no permite agregar una FOREIGN KEY a una tabla existente; definila en Schema::create().'
        );
    }

    /**
     * "constraint x foreign key (...) references ..." a partir del ALTER que
     * genera PostgresGrammar (reutiliza on delete/update, deferrable, etc.).
     */
    protected function compileInlineForeign(Blueprint $blueprint, Fluent $command): string
    {
        $sql = parent::compileForeign($blueprint, $command);
        $prefix = 'alter table '.$this->wrapTable($blueprint).' add ';

        return substr($sql, strlen($prefix));
    }

    /** Indice simple/compuesto -> CREATE INDEX ASYNC */
    public function compileIndex(Blueprint $blueprint, Fluent $command)
    {
        return sprintf('create index async %s on %s%s (%s)',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            $command->algorithm ? ' using '.$command->algorithm : '',
            $this->columnize($command->columns)
        );
    }

    /** Unico (incl. compuesto) -> CREATE UNIQUE INDEX ASYNC */
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
     * DSQL no soporta pg_total_relation_size (y pg_relation_size devuelve 0),
     * asi que el listado de tablas va sin tamano. Lo usan Schema::getTables(),
     * db:show, db:wipe y migrate:fresh.
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
     * Auto-increment: DSQL no tiene 'serial'. Toda columna autoIncrement pasa a
     * bigint IDENTITY con CACHE 65536 ($table->id(), increments(), y la tabla
     * 'migrations' de Laravel). La PK de esas columnas ya sale inline: el
     * modifyIncrement() de PostgresGrammar agrega ' primary key' a la columna.
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
