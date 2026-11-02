<?php

namespace Multiapi\LaravelAuroraDsql\Tests\Unit;

use Multiapi\LaravelAuroraDsql\Database\DsqlConnection;
use Multiapi\LaravelAuroraDsql\Database\DsqlQueryGrammar;
use Multiapi\LaravelAuroraDsql\Database\DsqlSchemaGrammar;
use Multiapi\LaravelAuroraDsql\DsqlServiceProvider;
use Multiapi\LaravelAuroraDsql\Migrations\DsqlMigration;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LegacyNamespaceTest extends TestCase
{
    #[Test]
    public function the_jgamboa_namespace_aliases_every_class(): void
    {
        foreach ([
            DsqlServiceProvider::class,
            DsqlConnection::class,
            DsqlQueryGrammar::class,
            DsqlSchemaGrammar::class,
            DsqlMigration::class,
        ] as $class) {
            $legacy = str_replace('Multiapi\\', 'JGamboa\\', $class);

            $this->assertTrue(class_exists($legacy, false), $legacy);
            $this->assertSame($class, (new \ReflectionClass($legacy))->getName());
        }
    }

    #[Test]
    public function legacy_type_checks_accept_the_new_classes(): void
    {
        $connection = new DsqlConnection(new PDO('sqlite::memory:'), ':memory:', '', ['name' => 'dsql']);

        $this->assertInstanceOf(\JGamboa\LaravelAuroraDsql\Database\DsqlConnection::class, $connection);
        $this->assertSame($connection, (fn (\JGamboa\LaravelAuroraDsql\Database\DsqlConnection $c) => $c)($connection));
    }
}
