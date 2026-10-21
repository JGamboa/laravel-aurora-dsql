<?php

namespace JGamboa\LaravelAuroraDsql\Database;

use Closure;
use Illuminate\Database\PostgresConnection;
use Throwable;

/**
 * Aurora DSQL connection: a PostgresConnection with the DSQL schema grammar.
 * The PDO is created by DsqlServiceProvider using the official connector
 * (IAM token + SSL).
 */
class DsqlConnection extends PostgresConnection
{
    private const OCC_BASE_DELAY_MS = 50;

    private const OCC_MAX_DELAY_MS = 1000;

    /** @var (callable(int): void)|null Replaces usleep; for tests only. */
    private static $sleeper = null;

    /**
     * Swap the backoff sleeper (receives milliseconds). Pass null to restore
     * the real sleep. Intended for tests.
     *
     * @param  (callable(int): void)|null  $sleeper
     */
    public static function sleepUsing(?callable $sleeper): void
    {
        self::$sleeper = $sleeper;
    }

    /**
     * Run a transaction, retrying the whole closure on OCC conflicts
     * (SQLSTATE 40001, OC000/OC001) with exponential backoff and jitter.
     * With $attempts = null, 'occ_max_retries' (default 3) + 1 attempts are made.
     * The closure must be idempotent: it can run several times.
     */
    public function transaction(Closure $callback, $attempts = null)
    {
        // Nested transaction: a conflict must bubble to the outermost loop.
        if ($this->transactionLevel() > 0) {
            return parent::transaction($callback, 1);
        }

        $attempts ??= $this->occMaxAttempts();

        for ($attempt = 1; ; $attempt++) {
            try {
                return parent::transaction($callback, 1);
            } catch (Throwable $e) {
                // A failed COMMIT never reaches the transactions manager in
                // Laravel, so the attempt's afterCommit callbacks would stay
                // registered and fire on a later commit. Discard them here
                // (level is 0: outer transactions cannot exist).
                $this->transactionsManager?->rollback($this->getName(), $this->transactionLevel());

                if ($attempt >= $attempts || ! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }

                $this->occBackoff($attempt);
            }
        }
    }

    /**
     * Autocommit writes (INSERT/UPDATE/DELETE outside a transaction) are
     * retried on OCC conflicts. Inside a transaction the conflict bubbles up
     * to the transaction() loop instead.
     */
    protected function run($query, $bindings, Closure $callback)
    {
        if ($this->transactionLevel() > 0 || ! preg_match('/^\s*(insert|update|delete)\b/i', $query)) {
            return parent::run($query, $bindings, $callback);
        }

        $attempts = $this->occMaxAttempts();

        for ($attempt = 1; ; $attempt++) {
            try {
                return parent::run($query, $bindings, $callback);
            } catch (Throwable $e) {
                if ($attempt >= $attempts || ! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }

                $this->occBackoff($attempt);
            }
        }
    }

    private function occMaxAttempts(): int
    {
        return max(0, (int) ($this->getConfig('occ_max_retries') ?? 3)) + 1;
    }

    /** Exponential backoff (base 50ms, cap 1000ms) with jitter floored at half the cap. */
    private function occBackoff(int $attempt): void
    {
        $cap = min(self::OCC_MAX_DELAY_MS, self::OCC_BASE_DELAY_MS * 2 ** ($attempt - 1));
        $ms = random_int(intdiv($cap, 2), $cap);

        if (self::$sleeper !== null) {
            (self::$sleeper)($ms);
        } else {
            usleep($ms * 1000);
        }
    }

    public function getDriverName()
    {
        // Behaves as Postgres internally (query grammar, processor).
        return 'pgsql';
    }

    protected function getDefaultSchemaGrammar()
    {
        return new DsqlSchemaGrammar($this);
    }

    /**
     * CREATE [UNIQUE] INDEX ASYNC returns a job_id and the index is not valid
     * until the job finishes. By default we wait for it (CALL sys.wait_for_job)
     * so later migrations can rely on the index; disable it with
     * 'wait_for_indexes' => false in the connection config.
     */
    public function statement($query, $bindings = [])
    {
        if ($this->pretending() || ! preg_match('/^\s*create\s+(unique\s+)?index\s+async\b/i', $query)) {
            return parent::statement($query, $bindings);
        }

        $jobId = $this->selectOne($query, $bindings, false)?->job_id;

        if ($jobId && $this->getConfig('wait_for_indexes') !== false) {
            parent::statement('call sys.wait_for_job(?)', [$jobId]);
        }

        return true;
    }
}
