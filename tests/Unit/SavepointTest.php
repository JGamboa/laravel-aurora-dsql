<?php

namespace JGamboa\LaravelAuroraDsql\Tests\Unit;

use JGamboa\LaravelAuroraDsql\Database\DsqlConnection;
use Orchestra\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

class SavepointTest extends TestCase
{
    #[Test]
    public function nested_transactions_never_issue_savepoints(): void
    {
        $pdo = new class('sqlite::memory:') extends PDO
        {
            /** @var list<string> */
            public array $executed = [];

            public function exec(string $statement): int|false
            {
                $this->executed[] = $statement;

                return parent::exec($statement);
            }
        };
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection = new DsqlConnection($pdo, ':memory:', '', ['name' => 'dsql']);
        $connection->statement('create table t (a integer)');

        $connection->transaction(function () use ($connection) {
            $connection->insert('insert into t (a) values (1)');
            $connection->transaction(fn () => $connection->insert('insert into t (a) values (2)'));
        });

        $this->assertFalse($connection->getQueryGrammar()->supportsSavepoints());
        $this->assertSame([], array_values(array_filter($pdo->executed, fn (string $sql) => stripos($sql, 'savepoint') !== false)));
        $this->assertSame(2, $connection->table('t')->count());
        $this->assertSame(0, $connection->transactionLevel());
    }
}
