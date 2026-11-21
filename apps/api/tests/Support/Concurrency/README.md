# PostgreSQL concurrency harness

Run from `apps/api` with PHP 8.4, installed Composer dependencies, PostgreSQL 16
and Redis 7. CI's `integration` job already provisions both services and runs:

```sh
php artisan migrate --force
php artisan test --testsuite=Concurrency
```

For local Docker services:

```sh
docker run -d --name elzara-test-postgres -p 127.0.0.1:5432:5432 \
  -e POSTGRES_DB=elzara_test -e POSTGRES_USER=elzara -e POSTGRES_PASSWORD=elzara postgres:16-alpine
docker run -d --name elzara-test-redis -p 127.0.0.1:6379:6379 redis:7-alpine
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432
export DB_DATABASE=elzara_test DB_USERNAME=elzara DB_PASSWORD=elzara
export REDIS_HOST=127.0.0.1 REDIS_PORT=6379
touch .env
```

Wait for PostgreSQL readiness before migrating. Set a testing `APP_KEY` and
`APP_ENV=testing` when running Artisan outside PHPUnit.

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
