<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Tests\TestCase;

abstract class ConcurrencyTestCase extends TestCase
{
    public function createApplication()
    {
        foreach (ContainerEnvironment::start() as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        return parent::createApplication();
    }
}
