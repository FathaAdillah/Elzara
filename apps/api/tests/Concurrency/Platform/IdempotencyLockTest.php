<?php

declare(strict_types=1);

namespace Tests\Concurrency\Platform;

use App\Platform\Http\IdempotencyStore;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class IdempotencyLockTest extends TestCase
{
    private const KEY = 'a8d4bccc-56a7-40ad-a0f1-a4761fc6886f';

    private const CONTENDER = 'idempotency_contender';

    public function test_independent_connections_cannot_apply_the_same_key_twice(): void
    {
        $schema = 'idem_lock_'.bin2hex(random_bytes(8));
        DB::statement("CREATE SCHEMA {$schema}");
        $primary = DB::getDefaultConnection();

        try {
            DB::statement("SET search_path TO {$schema}");
            (require app_path('Platform/Infrastructure/Database/Migrations/0001_Platform_create_reliability_tables.php'))->up();
            (require app_path('Platform/Infrastructure/Database/Migrations/0080_Platform_add_idempotency_response_snapshot.php'))->up();
            Schema::create('idempotency_effects', static function (Blueprint $table): void {
                $table->string('owner');
            });

            config(['database.connections.'.self::CONTENDER => config('database.connections.pgsql')]);
            $contender = DB::connection(self::CONTENDER);
            $contender->statement("SET search_path TO {$schema}");
            $contender->statement("SET lock_timeout TO '200ms'");
            $store = app(IdempotencyStore::class);
            $second = static function (): Response {
                DB::table('idempotency_effects')->insert(['owner' => 'contender']);

                return new Response('{"owner":"contender"}', 201);
            };

            $first = $this->runDelivery($store, $primary, function () use ($store, $second): Response {
                DB::table('idempotency_effects')->insert(['owner' => 'winner']);
                try {
                    $this->runDelivery($store, self::CONTENDER, $second);
                    $this->fail('The other connection must wait for the conflicting key.');
                } catch (QueryException $exception) {
                    $this->assertSame('55P03', $exception->errorInfo[0]);
                }

                return new Response('{"owner":"winner"}', 201, ['Content-Type' => 'application/json']);
            });

            $replay = $this->runDelivery($store, self::CONTENDER, $second);
            $this->assertNotNull($first);
            $this->assertNotNull($replay);
            $this->assertSame(201, $replay->getStatusCode());
            $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));
            $this->assertSame($first->getContent(), $replay->getContent());
            $this->assertSame(['winner'], DB::table('idempotency_effects')->pluck('owner')->all());
            $this->assertSame(1, DB::table('idempotency_keys')->count());
        } finally {
            DB::setDefaultConnection($primary);
            DB::purge(self::CONTENDER);
            DB::statement('RESET search_path');
            DB::statement("DROP SCHEMA {$schema} CASCADE");
        }
    }

    /** @param Closure(): Response $effect */
    private function runDelivery(IdempotencyStore $store, string $connection, Closure $effect): ?Response
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($connection);
        try {
            return $store->run(1, 'createOrders', self::KEY, str_repeat('a', 64), 86400, 'fixture-request', $effect);
        } finally {
            DB::setDefaultConnection($previous);
        }
    }
}
