<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\Outbox\InboxConsumer;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class InboxConsumerTest extends TestCase
{
    private const EVENT = 'a8d4bccc-56a7-40ad-a0f1-a4761fc6886f';

    private ?string $fixtureSchema = null;

    protected function setUp(): void
    {
        parent::setUp();

        $schema = 'inbox_feature_'.bin2hex(random_bytes(8));
        DB::statement("CREATE SCHEMA {$schema}");
        $this->fixtureSchema = $schema;
        DB::statement("SET search_path TO {$schema}");
        $migration = require app_path('Platform/Infrastructure/Database/Migrations/0001_Platform_create_reliability_tables.php');
        $migration->up();

        Schema::create('inbox_effects', function (Blueprint $table): void {
            $table->string('consumer')->primary();
        });
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixtureSchema !== null) {
                DB::purge('inbox_contender');
                DB::statement('RESET search_path');
                DB::statement("DROP SCHEMA {$this->fixtureSchema} CASCADE");
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_delivery_is_processed_once_per_consumer(): void
    {
        Log::spy();
        $payload = ['order_id' => 42, 'detail' => ['active' => true]];
        $consumer = app(InboxConsumer::class);

        $this->assertTrue($consumer->run('inventory', self::EVENT, $payload, $this->effect('inventory')));
        $row = $this->delivery('inventory');
        $this->assertSame('processed', $row->status);
        $this->assertNotNull($row->processed_at);
        $this->assertNull($row->error);
        $this->assertEquals($payload, json_decode($row->payload, true, 8, JSON_THROW_ON_ERROR));

        $this->assertFalse($consumer->run('inventory', self::EVENT, [], $this->effect('inventory')));
        Log::shouldHaveReceived('info')->once()->with('inbox.dedup_ignored', [
            'consumer' => 'inventory',
            'event_id' => self::EVENT,
        ]);
        $this->assertEquals($payload, json_decode($this->delivery('inventory')->payload, true, 8, JSON_THROW_ON_ERROR));
        $this->assertTrue($consumer->run('notification', self::EVENT, $payload, $this->effect('notification')));
        $this->assertSame(2, DB::table('inbox_effects')->count());
        $this->assertSame(2, DB::table('inbox_events')->where('status', 'processed')->count());
    }

    #[DataProvider('failedEffects')]
    public function test_failed_effects_roll_back_and_retry_safely(bool $simulateDatabaseError): void
    {
        $original = null;
        $consumer = app(InboxConsumer::class);

        try {
            $consumer->run('inventory', self::EVENT, ['order_id' => 42], function (ConnectionInterface $db) use (&$original, $simulateDatabaseError): void {
                $db->table('inbox_effects')->insert(['consumer' => 'inventory']);
                try {
                    if ($simulateDatabaseError) {
                        $db->table('inbox_effects')->insert(['consumer' => 'inventory']);
                    }

                    throw new RuntimeException('Internal fixture message');
                } catch (Throwable $exception) {
                    $original = $exception;
                    throw $exception;
                }
            });
            $this->fail('The consumer must propagate the original exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame($original, $exception);
            if ($simulateDatabaseError) {
                $this->assertInstanceOf(QueryException::class, $exception);
                $this->assertSame('23505', $exception->errorInfo[0]);
            }
        }

        $row = $this->delivery('inventory');
        $this->assertSame('failed', $row->status);
        $this->assertSame('Efek konsumen gagal.', $row->error);
        $this->assertNull($row->processed_at);
        $this->assertSame(0, DB::table('inbox_effects')->count());

        $this->assertTrue($consumer->run('inventory', self::EVENT, ['order_id' => 42], $this->effect('inventory')));
        $row = $this->delivery('inventory');
        $this->assertSame('processed', $row->status);
        $this->assertNull($row->error);
        $this->assertNotNull($row->processed_at);
        $this->assertSame(1, DB::table('inbox_effects')->count());
    }

    public static function failedEffects(): array
    {
        return [
            'domain failure' => [false],
            'PostgreSQL constraint failure' => [true],
        ];
    }

    public function test_competing_delivery_waits_for_claim_and_then_is_ignored(): void
    {
        config(['database.connections.inbox_contender' => config('database.connections.pgsql')]);
        $contenderDb = DB::connection('inbox_contender');
        $contenderDb->statement("SET search_path TO {$this->fixtureSchema}");
        $contenderDb->statement("SET lock_timeout TO '200ms'");
        $contender = new InboxConsumer('inbox_contender');

        $this->assertTrue(app(InboxConsumer::class)->run('inventory', self::EVENT, [], function (ConnectionInterface $db) use ($contender): void {
            try {
                $contender->run('inventory', self::EVENT, [], $this->effect('contender'));
                $this->fail('A competing delivery must wait for ownership.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
            }

            $db->table('inbox_effects')->insert(['consumer' => 'inventory']);
        }));

        $this->assertFalse($contender->run('inventory', self::EVENT, [], $this->effect('contender')));
        $this->assertSame(['inventory'], DB::table('inbox_effects')->pluck('consumer')->all());
    }

    private function delivery(string $consumer): object
    {
        return DB::table('inbox_events')->where('consumer', $consumer)->where('event_id', self::EVENT)->first();
    }

    private function effect(string $consumer): callable
    {
        return static function (ConnectionInterface $db) use ($consumer): void {
            $db->table('inbox_effects')->insert(['consumer' => $consumer]);
        };
    }
}
