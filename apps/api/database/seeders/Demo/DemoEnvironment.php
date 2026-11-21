<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use RuntimeException;

final class DemoEnvironment
{
    public static function assertNotProduction(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoSeeder tidak boleh berjalan di environment production');
        }
    }
}
