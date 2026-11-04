<?php

namespace Multiapi\LaravelAuroraDsql\Tests\Unit;

use Multiapi\LaravelAuroraDsql\Database\DsqlConnection;
use Multiapi\LaravelAuroraDsql\DsqlServiceProvider;
use Orchestra\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

class EmulatedPreparesTest extends TestCase
{
    #[Test]
    public function dsql_connections_use_emulated_prepares(): void
    {
        $attributes = (new DsqlServiceProvider($this->app))->pdoAttributes();

        $this->assertTrue($attributes[PDO::ATTR_EMULATE_PREPARES] ?? null);
    }

    #[Test]
    public function the_connection_config_reports_emulated_prepares(): void
    {
        $config = (new DsqlServiceProvider($this->app))->connectionConfig(
            ['driver' => 'dsql', 'options' => [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_EMULATE_PREPARES => false]],
            'dsql',
        );

        $this->assertSame('dsql', $config['name']);
        $this->assertTrue($config['options'][PDO::ATTR_EMULATE_PREPARES]);
        $this->assertSame(5, $config['options'][PDO::ATTR_TIMEOUT]);
    }

    #[Test]
    public function booleans_are_bound_as_literals_with_emulated_prepares(): void
    {
        $connection = new DsqlConnection(new PDO('sqlite::memory:'), 'postgres', '', ['name' => 'dsql']);

        $this->assertSame(['true', 'false', 1, '0'], $connection->prepareBindings([true, false, 1, '0']));
    }
}
