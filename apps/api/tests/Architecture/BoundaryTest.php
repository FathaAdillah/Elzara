<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Platform\Outbox\DomainEvent;
use App\Platform\Outbox\QueueRouting;
use App\Platform\StateMachine\Axis;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Finder\Finder;

final class BoundaryTest extends TestCase
{
    public function test_b1_module_writes_only_its_tables_and_does_not_import_foreign_models(): void
    {
        $owners = [];
        foreach ($this->modules() as $file) {
            if (str_contains($file->getRelativePath(), '/Database/Migrations')) {
                preg_match_all("~Schema::create\(\s*['\"]([a-z_]+)['\"]~i", $file->getContents(), $tables);
                foreach ($tables[1] as $table) {
                    $this->assertArrayNotHasKey($table, $owners, "{$table} is created by more than one module migration.");
                    $owners[$table] = $this->module($file->getRelativePath());
                }
            }
        }
        $this->assertSame('Orders', $owners['orders'] ?? null);
        $violations = [];
        foreach ($this->modules() as $file) {
            $path = $file->getRelativePathname();
            $module = $this->module($path);
            preg_match_all('~App\\\\Modules\\\\(\w+)\\\\Infrastructure\\\\Models\\\\~i', $file->getContents(), $models);
            foreach (array_diff($models[1], [$module]) as $owner) {
                $violations[] = "{$path} references a {$owner} model";
            }
            foreach ($this->tableWrites($file->getContents()) as [, , $table]) {
                if (isset($owners[$table]) && $owners[$table] !== $module) {
                    $violations[] = "{$path} writes {$owners[$table]}.{$table}";
                }
            }
        }
        $this->assertSame([], $violations);
    }

    public function test_b2_http_adapters_do_not_write_storage_or_use_models(): void
    {
        $violations = [];
        $controllers = Finder::create()->files()->in(__DIR__.'/../../app')->name('*Controller.php');
        foreach ($controllers as $file) {
            $source = $file->getContents();
            if (preg_match('~(?:\bDB::|Facades\\\\DB\b|Infrastructure\\\\Models\\\\|Database\\\\Eloquent\\\\|->(?:save|forceDelete)\s*\(\s*\)|::(?:create|updateOrCreate)\s*\()~', $source) === 1) {
                $violations[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $violations, 'Controllers must delegate storage and mutations to the application layer.');
    }

    public function test_b3_domain_event_rejects_objects_even_inside_a_projection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DomainEvent('OrderPlaced', 'order', 1, ['order' => ['user' => new stdClass]]);
    }

    public function test_b4_modules_publish_domain_events_via_outbox_not_direct_dispatch(): void
    {
        $violations = [];
        foreach ($this->modules() as $file) {
            if (preg_match('~\bEvent::(?:dispatch|fire)\s*\(|\bevent\s*\(|DomainEvent::dispatch\s*\(|->afterCommit\s*\(~', $file->getContents()) === 1) {
                $violations[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_b5_order_state_uses_the_runner_and_financial_updates_stay_in_the_owning_application(): void
    {
        $violations = [];
        $protectedOrderColumns = [...array_column(Axis::cases(), 'value'), 'subtotal', 'shipping_total', 'total'];
        $financialTables = ['invoices' => 'Billing', 'payment_intents' => 'Payments'];
        foreach ($this->modules()->notPath('/Database\/Migrations\//') as $file) {
            $source = $file->getContents();
            $path = $file->getRelativePathname();
            foreach ($this->tableWrites($source) as [, , $table, $method, $values]) {
                if (isset($financialTables[$table]) && ! str_starts_with($path, $financialTables[$table].'/Application/')) {
                    $violations[] = $path;
                }
                if ($table !== 'orders' || str_starts_with($method, 'insert') || $method === 'delete') {
                    continue;
                }
                preg_match_all("~['\"](\w+)['\"]\s*=>~", $values, $keys);
                if ($method !== 'update' || ! str_starts_with(ltrim($values), '[')
                    || $keys[1] === [] || array_intersect($keys[1], $protectedOrderColumns) !== []) {
                    $violations[] = $path;
                }
            }
            if (preg_match("~['\"]\s*update\s+(?:orders|invoices|payment_intents)\\b~i", $source) === 1) {
                $violations[] = $path;
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_b6_module_migrations_name_their_owner_in_the_registered_directory(): void
    {
        $violations = [];
        $count = 0;
        foreach ($this->modules() as $file) {
            if (! str_contains($file->getRelativePath(), 'Database/Migrations') && ! preg_match('/^\d{4}_/', $file->getFilename())) {
                continue;
            }
            $owner = $this->module($file->getRelativePath());
            $count++;
            if ($file->getRelativePath() !== "{$owner}/Infrastructure/Database/Migrations"
                || ! preg_match('/^\d{4}_'.preg_quote($owner, '/').'_[a-z0-9_]+\.php$/', $file->getFilename())) {
                $violations[] = $file->getRelativePathname();
            }
        }

        $this->assertGreaterThan(0, $count);
        $this->assertSame([], $violations);
    }

    public function test_horizon_runs_only_the_canonical_queues_in_both_environments(): void
    {
        $horizon = require __DIR__.'/../../config/horizon.php';
        $expected = ['payments', 'orders', 'inventory', 'notifications', 'imports', 'reports', 'media'];
        $this->assertEqualsCanonicalizing($expected, QueueRouting::QUEUES);
        $this->assertEqualsCanonicalizing($expected, array_values(array_unique(QueueRouting::MAP)));
        foreach (['staging', 'production'] as $environment) {
            $supervisors = $horizon['environments'][$environment];
            $this->assertEqualsCanonicalizing($expected, array_keys($supervisors));
            foreach ($supervisors as $queue => $config) {
                $this->assertSame([$queue], $config['queue']);
            }
        }
        foreach (Finder::create()->files()->in(__DIR__.'/../../app')->name('*.php') as $file) {
            preg_match_all("~->onQueue\(\s*['\"]([^'\"]+)['\"]~", $file->getContents(), $matches);
            foreach ($matches[1] as $queue) {
                $this->assertContains($queue, $expected, $file->getRelativePathname());
            }
        }
    }

    private function modules(): Finder
    {
        return Finder::create()->files()->in(__DIR__.'/../../app/Modules')->name('*.php');
    }

    private function module(string $path): string
    {
        return explode('/', $path, 2)[0];
    }

    /** @return list<array{string, string, string, string, string}> */
    private function tableWrites(string $source): array
    {
        preg_match_all(
            '~(?:->|::)table\(\s*([\'"])([a-z_]+)\1\s*\)[^;]*?->(insert(?:GetId|OrIgnore)?|upsert|update(?:OrInsert)?|increment|decrement|delete|truncate)\s*\(([^;]*)~s',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        return $matches;
    }
}
