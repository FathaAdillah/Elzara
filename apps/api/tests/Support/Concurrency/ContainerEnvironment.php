<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Symfony\Component\Process\Process;
use Testcontainers\Container\GenericContainer;
use Testcontainers\Container\StartedTestContainer;
use Testcontainers\Utils\PortGenerator\PortGenerator;
use Testcontainers\Wait\WaitForExec;
use Throwable;

final class ContainerEnvironment
{
    /** @var list<StartedTestContainer> */
    private static array $containers = [];

    /** @var array<string, string> */
    private static array $environment = [];

    /** @return array<string, string> */
    public static function start(): array
    {
        if (self::$environment !== []) {
            return self::$environment;
        }

        register_shutdown_function(self::stop(...));
        try {
            $ports = new class implements PortGenerator
            {
                public function generatePort(): int
                {
                    return 0;
                }
            };
            $postgres = (new GenericContainer('postgres:16-alpine'))
                ->withEnvironment(['POSTGRES_DB' => 'elzara_test', 'POSTGRES_USER' => 'elzara', 'POSTGRES_PASSWORD' => 'elzara'])
                ->withPortGenerator($ports)->withExposedPorts(5432)->withAutoRemove(true)->start();
            self::$containers[] = $postgres;
            (new WaitForExec(['pg_isready', '-h', '127.0.0.1', '-U', 'elzara', '-d', 'elzara_test'], timeout: 60000))->wait($postgres);

            $redis = (new GenericContainer('redis:7-alpine'))
                ->withPortGenerator($ports)->withExposedPorts(6379)->withAutoRemove(true)->start();
            self::$containers[] = $redis;
            (new WaitForExec(['redis-cli', 'ping'], timeout: 30000))->wait($redis);

            $environment = [
                'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
                'DB_HOST' => $postgres->getHost(), 'DB_PORT' => (string) $postgres->getMappedPort(5432),
                'DB_DATABASE' => 'elzara_test', 'DB_USERNAME' => 'elzara', 'DB_PASSWORD' => 'elzara',
                'REDIS_URL' => '', 'REDIS_HOST' => $redis->getHost(), 'REDIS_PORT' => (string) $redis->getMappedPort(6379),
                'REDIS_USERNAME' => '', 'REDIS_PASSWORD' => '',
                'APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                'APP_CONFIG_CACHE' => dirname(__DIR__, 3).'/bootstrap/cache/concurrency-config.php',
            ];
            (new Process([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], dirname(__DIR__, 3), $environment, timeout: 120))->mustRun();
            self::$environment = $environment;

            return $environment;
        } catch (Throwable $failure) {
            self::stop();
            throw $failure;
        }
    }

    public static function stop(): void
    {
        while ($container = array_pop(self::$containers)) {
            $container->stop();
        }
        self::$environment = [];
    }
}
