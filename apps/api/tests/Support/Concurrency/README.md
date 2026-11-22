# PostgreSQL concurrency harness

Run from `apps/api` with PHP 8.4 (pdo_pgsql and redis extensions), installed
Composer dev dependencies and a running Docker daemon accessible to your user:

```sh
touch .env
php artisan test --testsuite=Concurrency
```

No pre-existing database or Redis is needed. Use an empty `.env` (move any local
E2E environment aside first). `ConcurrencyTestCase` starts one suite-owned pair
of `postgres:16-alpine` and `redis:7-alpine` containers through
`testcontainers/testcontainers`, waits for readiness and runs migrations before
Laravel boots. Host ports are random. DB/Redis URL and connection settings are
overridden, including the explicit environment passed to fresh worker processes.
The harness bypasses the normal cached Laravel config. Containers are stopped
and auto-removed at PHP shutdown, including after test failures; startup failures
also clean up containers already started. Docker/image-pull errors fail the suite
rather than skipping it. CI's integration step uses this same lifecycle, not its
Feature/Contract/Schema service database. There is no external-service opt-out.

`StockQuotaFixture` creates committed rows in a random schema. Always call
`close()` in `finally`; do not use a parent test transaction. `ParallelRunner::run`
accepts schema, `stock`/`quota`, worker count (default 2), attempts per worker
(default 20), and a timeout in seconds (default 10). Each fresh PHP process boots
Laravel, connects independently, signals READY, then waits for GO on stdin. The
parent releases the barrier only after every connection is ready. Results include
process/backend IDs, accepted quantities and observations after every attempt.
Errors, nonzero exits and timeouts throw; all workers are stopped in `finally`.
The `fail` and `timeout` modes exercise runner diagnostics.

These fixtures test atomic conditional decrements, not production checkout or
enrollment. The helpers check every observed availability (including rejected
attempts and the persisted final value), accepted capacity, and conservation.
The database CHECK constraint additionally guards every persisted row version.
Future production gates must supply their real business operations and ledger
assertions rather than claim fixture success as end-to-end acceptance. The large
1,000-checkout/500-enrollment staging load gates and Playwright journeys remain
separate work.
