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

class ItCategoria extends Model
{
    use HasUuids;

    protected $connection = 'dsql';
    protected $table = 'it_categorias';
    protected $guarded = [];
}

class ItProducto extends Model
{
    use HasUuids;

    protected $connection = 'dsql';
    protected $table = 'it_productos';
    protected $guarded = [];
}

/**
 * Contra un cluster real. Requiere DSQL_HOST (y DSQL_PROFILE si se usa SSO):
 *   DSQL_HOST=xxx.dsql.us-east-1.on.aws DSQL_PROFILE=mi-perfil vendor/bin/phpunit --testsuite Integration
 */
class SchemaBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('DSQL_HOST')) {
            $this->markTestSkipped('DSQL_HOST no definido.');
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
            Schema::connection('dsql')->dropIfExists('it_productos');
            Schema::connection('dsql')->dropIfExists('it_categorias');
            DB::connection('dsql')->table('migrations')->where('migration', 'like', '%create_it_tables')->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function migracion_con_schema_builder_y_eloquent_end_to_end(): void
    {
        $this->artisan('migrate', [
            '--database' => 'dsql',
            '--path' => __DIR__.'/migrations',
            '--realpath' => true,
        ])->assertSuccessful();

        $schema = Schema::connection('dsql');
        $this->assertTrue($schema->hasTable('it_productos'));

        $pk = collect($schema->getIndexes('it_productos'))->firstWhere('primary', true);
        $this->assertSame('id', $pk['columns'][0]);
        $this->assertCount(1, $schema->getForeignKeys('it_productos'));

        // Eloquent + HasUuids (v7) + default inline
        $cat = ItCategoria::create(['nombre' => 'cat-'.Str::random(6)]);
        $this->assertSame('7', Str::replace('-', '', $cat->id)[12]);
        $prod = ItProducto::create(['categoria_id' => $cat->id, 'sku' => 'A']);
        $this->assertSame(0, (int) $prod->fresh()->precio);

        // FK se hace cumplir
        try {
            ItProducto::create(['categoria_id' => (string) Str::uuid7(), 'sku' => 'X']);
            $this->fail('La FK no se hizo cumplir');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode());
        }

        // unique (index ASYNC, ya construido porque la conexion espera el job)
        $this->expectException(UniqueConstraintViolationException::class);
        try {
            ItCategoria::create(['nombre' => $cat->nombre]);
        } finally {
            // on delete cascade
            $cat->delete();
            $this->assertSame(0, ItProducto::count());
        }
    }
}
