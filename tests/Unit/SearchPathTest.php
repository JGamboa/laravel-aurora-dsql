<?php

namespace JGamboa\LaravelAuroraDsql\Tests\Unit;

use JGamboa\LaravelAuroraDsql\DsqlServiceProvider;
use Orchestra\Testbench\TestCase;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;

class SearchPathTest extends TestCase
{
    #[Test]
    public function the_configured_search_path_is_applied_to_the_session(): void
    {
        $pdo = $this->recordingPdo();

        $this->provider()->configureSearchPath($pdo, ['search_path' => 'shop_production']);

        $this->assertSame(['set search_path to "shop_production"'], $pdo->prepared);
    }

    #[Test]
    public function a_search_path_list_and_the_schema_key_are_supported(): void
    {
        $pdo = $this->recordingPdo();

        $this->provider()->configureSearchPath($pdo, ['search_path' => 'shop_production, public']);
        $this->provider()->configureSearchPath($pdo, ['schema' => ['shop_staging']]);

        $this->assertSame([
            'set search_path to "shop_production", "public"',
            'set search_path to "shop_staging"',
        ], $pdo->prepared);
    }

    #[Test]
    public function without_a_search_path_the_role_default_is_kept(): void
    {
        $pdo = $this->recordingPdo();

        $this->provider()->configureSearchPath($pdo, ['database' => 'postgres']);

        $this->assertSame([], $pdo->prepared);
    }

    private function provider(): DsqlServiceProvider
    {
        return new DsqlServiceProvider($this->app);
    }

    private function recordingPdo(): PDO
    {
        return new class('sqlite::memory:') extends PDO
        {
            /** @var list<string> */
            public array $prepared = [];

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->prepared[] = $query;

                return parent::prepare('select 1');
            }
        };
    }
}
