<?php

namespace JGamboa\LaravelAuroraDsql\Tests\Integration;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JGamboa\LaravelAuroraDsql\DsqlServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ItCategory extends Model
{
    use HasUuids;

    protected $connection = 'dsql';
    protected $table = 'it_categories';
    protected $guarded = [];
}

class ItProduct extends Model
{
    use HasUuids;

    protected $connection = 'dsql';
    protected $table = 'it_products';
    protected $guarded = [];
}

/**
 * Runs against a live cluster. Requires DSQL_HOST (and DSQL_PROFILE for SSO):
 *   DSQL_HOST=xxx.dsql.us-east-1.on.aws DSQL_PROFILE=my-profile vendor/bin/phpunit --testsuite Integration
 */
class SchemaBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('DSQL_HOST')) {
            $this->markTestSkipped('DSQL_HOST is not set.');
        }

        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [DsqlServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.connections.dsql', [
            'driver' => 'dsql',
            'host' => getenv('DSQL_HOST'),
            'region' => getenv('AWS_REGION') ?: 'us-east-1',
            'username' => 'admin',
            'database' => 'postgres',
            'profile' => getenv('DSQL_PROFILE') ?: null,
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            Schema::connection('dsql')->dropIfExists('it_products');
            Schema::connection('dsql')->dropIfExists('it_categories');
            DB::connection('dsql')->table('migrations')->where('migration', 'like', '%create_it_tables')->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function schema_builder_migration_and_eloquent_end_to_end(): void
    {
        $this->artisan('migrate', [
            '--database' => 'dsql',
            '--path' => __DIR__.'/migrations',
            '--realpath' => true,
        ])->assertSuccessful();

        $schema = Schema::connection('dsql');
        $this->assertTrue($schema->hasTable('it_products'));

        $pk = collect($schema->getIndexes('it_products'))->firstWhere('primary', true);
        $this->assertSame('id', $pk['columns'][0]);
        $this->assertCount(1, $schema->getForeignKeys('it_products'));

        // Eloquent + HasUuids (v7) + inline default
        $cat = ItCategory::create(['name' => 'cat-'.Str::random(6)]);
        $this->assertSame('7', Str::replace('-', '', $cat->id)[12]);
        $prod = ItProduct::create(['category_id' => $cat->id, 'sku' => 'A']);
        $this->assertSame(0, (int) $prod->fresh()->price);

        // the foreign key is enforced
        try {
            ItProduct::create(['category_id' => (string) Str::uuid7(), 'sku' => 'X']);
            $this->fail('The foreign key was not enforced');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode());
        }

        // unique (ASYNC index, already built because the connection waits for the job)
        $this->expectException(UniqueConstraintViolationException::class);
        try {
            ItCategory::create(['name' => $cat->name]);
        } finally {
            // on delete cascade
            $cat->delete();
            $this->assertSame(0, ItProduct::count());
        }
    }
}
