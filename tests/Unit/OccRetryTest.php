<?php

namespace JGamboa\LaravelAuroraDsql\Tests\Unit;

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use JGamboa\LaravelAuroraDsql\Database\DsqlConnection;
use Orchestra\Testbench\TestCase;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;

class OccRetryTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sleeps = [];
        DsqlConnection::sleepUsing(function (int $ms) {
            $this->sleeps[] = $ms;
        });
    }

    protected function tearDown(): void
    {
        DsqlConnection::sleepUsing(null);

        parent::tearDown();
    }

    private function dsqlOverSqlite(array $config = [], ?PDO $pdo = null): DsqlConnection
    {
        $pdo ??= new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new DsqlConnection($pdo, ':memory:', '', ['name' => 'dsql'] + $config);
    }

    private function pdoException(): PDOException
    {
        $e = new PDOException('OC000: change conflicts with another transaction');
        (new ReflectionProperty(PDOException::class, 'code'))->setValue($e, '40001');

        return $e;
    }

    private function occError(): QueryException
    {
        return new QueryException('dsql', 'update t set a = 1', [], $this->pdoException());
    }

    #[Test]
    public function transaction_retries_a_40001_failure_with_backoff_and_succeeds(): void
    {
        $conn = $this->dsqlOverSqlite(['occ_max_retries' => 3]);
        $calls = 0;

        $result = $conn->transaction(function () use (&$calls) {
            if (++$calls < 3) {
                throw $this->occError();
            }

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
        $this->assertCount(2, $this->sleeps);
        $this->assertGreaterThanOrEqual($this->sleeps[0] / 2, $this->sleeps[1]);
        $this->assertGreaterThanOrEqual(25, $this->sleeps[0]);
        $this->assertLessThanOrEqual(50, $this->sleeps[0]);
        $this->assertGreaterThanOrEqual(50, $this->sleeps[1]);
        $this->assertLessThanOrEqual(100, $this->sleeps[1]);
        $this->assertSame(0, $conn->transactionLevel());
    }

    #[Test]
    public function transaction_gives_up_after_occ_max_retries_plus_one_attempts(): void
    {
        $conn = $this->dsqlOverSqlite(['occ_max_retries' => 3]);
        $calls = 0;

        try {
            $conn->transaction(function () use (&$calls) {
                $calls++;

                throw $this->occError();
            });
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('40001', $e->getCode());
        }

        $this->assertSame(4, $calls);
        $this->assertCount(3, $this->sleeps);
        $this->assertSame(0, $conn->transactionLevel());
    }

    #[Test]
    public function non_concurrency_errors_are_not_retried(): void
    {
        $conn = $this->dsqlOverSqlite();
        $calls = 0;

        try {
            $conn->transaction(function () use (&$calls) {
                $calls++;

                throw new RuntimeException('boom');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(1, $calls);
        $this->assertSame([], $this->sleeps);
    }

    #[Test]
    public function autocommit_write_is_retried_on_40001(): void
    {
        $pdo = new FlakyPdo('sqlite::memory:');
        $pdo->failures = 1;
        $conn = $this->dsqlOverSqlite(['occ_max_retries' => 3], $pdo);
        $conn->statement('create table t (a integer)');
        $pdo->prepared = 0;

        $conn->statement('insert into t (a) values (1)');

        $this->assertSame(2, $pdo->prepared);
        $this->assertCount(1, $this->sleeps);
        $this->assertSame(1, (int) $conn->selectOne('select count(*) as c from t')->c);
    }

    #[Test]
    public function writes_inside_an_open_transaction_are_not_retried_individually(): void
    {
        $pdo = new FlakyPdo('sqlite::memory:');
        $conn = $this->dsqlOverSqlite(['occ_max_retries' => 3], $pdo);
        $conn->statement('create table t (a integer)');
        $pdo->prepared = 0;
        $pdo->failures = 1;
        $calls = 0;

        $conn->transaction(function () use ($conn, &$calls) {
            $calls++;
            $conn->statement('insert into t (a) values (1)');
        });

        // The failed insert bubbled to the transaction loop, which re-ran the closure.
        $this->assertSame(2, $calls);
        $this->assertSame(2, $pdo->prepared);
        $this->assertCount(1, $this->sleeps);
        $this->assertSame(1, (int) $conn->selectOne('select count(*) as c from t')->c);
    }

    private function commitFlakyConnection(int $commitFailures, int $retries = 3): array
    {
        $pdo = new FlakyPdo('sqlite::memory:');
        $pdo->commitFailures = $commitFailures;
        $conn = $this->dsqlOverSqlite(['occ_max_retries' => $retries], $pdo);
        $conn->setTransactionManager(new DatabaseTransactionsManager);

        return [$conn, $pdo];
    }

    #[Test]
    public function after_commit_callback_runs_once_when_commit_conflicts_then_succeeds(): void
    {
        [$conn] = $this->commitFlakyConnection(1);
        $ran = 0;

        $conn->transaction(function () use ($conn, &$ran) {
            $conn->afterCommit(function () use (&$ran) {
                $ran++;
            });
        });

        $this->assertSame(1, $ran);
        $this->assertCount(1, $this->sleeps);
    }

    #[Test]
    public function after_commit_callbacks_never_run_when_all_commit_attempts_fail(): void
    {
        [$conn, $pdo] = $this->commitFlakyConnection(99, 2);
        $ran = 0;

        try {
            $conn->transaction(function () use ($conn, &$ran) {
                $conn->afterCommit(function () use (&$ran) {
                    $ran++;
                });
            });
            $this->fail('Expected conflict');
        } catch (PDOException $e) {
            $this->assertSame('40001', $e->getCode());
        }

        $pdo->commitFailures = 0;
        $conn->transaction(fn () => null);

        $this->assertSame(0, $ran);
    }

    #[Test]
    public function after_commit_callbacks_from_failed_closure_attempts_are_discarded(): void
    {
        [$conn] = $this->commitFlakyConnection(0);
        $ran = [];
        $calls = 0;

        $conn->transaction(function () use ($conn, &$ran, &$calls) {
            $n = ++$calls;
            $conn->afterCommit(function () use (&$ran, $n) {
                $ran[] = $n;
            });
            if ($n < 3) {
                throw $this->occError();
            }
        });

        $this->assertSame([3], $ran);

        // All attempts fail: nothing runs, even after a later successful transaction.
        $stale = 0;
        try {
            $conn->transaction(function () use ($conn, &$stale) {
                $conn->afterCommit(function () use (&$stale) {
                    $stale++;
                });

                throw $this->occError();
            });
        } catch (QueryException) {
        }
        $conn->transaction(fn () => null);

        $this->assertSame(0, $stale);
    }
}

/** PDO that fails the next prepare() calls to INSERT with a 40001 error. */
class FlakyPdo extends PDO
{
    public int $failures = 0;

    public int $prepared = 0;

    public int $commitFailures = 0;

    public function commit(): bool
    {
        if ($this->commitFailures > 0) {
            $this->commitFailures--;
            $this->rollBack();
            $e = new PDOException('OC000: change conflicts with another transaction');
            (new ReflectionProperty(PDOException::class, 'code'))->setValue($e, '40001');

            throw $e;
        }

        return parent::commit();
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (preg_match('/^\s*insert/i', $query)) {
            $this->prepared++;

            if ($this->failures > 0) {
                $this->failures--;
                $e = new PDOException('OC000: change conflicts with another transaction');
                (new ReflectionProperty(PDOException::class, 'code'))->setValue($e, '40001');

                throw $e;
            }
        }

        return parent::prepare($query, $options);
    }
}
