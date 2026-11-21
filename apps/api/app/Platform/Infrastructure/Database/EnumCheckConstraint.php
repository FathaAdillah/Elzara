<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure\Database;

use App\Platform\StateMachine\Axis;
use BackedEnum;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

final class EnumCheckConstraint
{
    public function __construct(private readonly Connection $connection) {}

    /** @param class-string<BackedEnum> $enum */
    public function add(string $table, string $column, string $enum): void
    {
        $this->assertPostgres();
        $values = array_map(function (BackedEnum $case): string {
            $quoted = $this->connection->getPdo()->quote((string) $case->value);

            if ($quoted === false) {
                throw new RuntimeException('Unable to quote enum value.');
            }

            return $quoted;
        }, $enum::cases());

        if ($values === []) {
            throw new InvalidArgumentException('A CHECK constraint requires at least one enum case.');
        }

        $grammar = $this->connection->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);
        $name = self::name($table, $column);
        $exists = $this->connection->selectOne(
            'SELECT 1 FROM pg_constraint WHERE conrelid = ?::regclass AND conname = ?',
            [$wrappedTable, $name],
        );

        if ($exists !== null) {
            return;
        }

        $this->connection->statement('ALTER TABLE '.$wrappedTable.' ADD CONSTRAINT '.$grammar->wrap($name)
            .' CHECK ('.$grammar->wrap($column).' IN ('.implode(', ', $values).'))');
    }

    public function addAxis(string $table, Axis $axis): void
    {
        $this->add($table, $axis->value, $axis->enumClass());
    }

    public function drop(string $table, string $column): void
    {
        $this->assertPostgres();
        $grammar = $this->connection->getQueryGrammar();
        $this->connection->statement('ALTER TABLE '.$grammar->wrapTable($table)
            .' DROP CONSTRAINT IF EXISTS '.$grammar->wrap(self::name($table, $column)));
    }

    public function dropAxis(string $table, Axis $axis): void
    {
        $this->drop($table, $axis->value);
    }

    public static function name(string $table, string $column): string
    {
        return 'ck_'.substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $table.'_'.$column) ?? '', 0, 40)
            .'_'.substr(hash('sha256', $table."\0".$column), 0, 16);
    }

    private function assertPostgres(): void
    {
        if ($this->connection->getDriverName() !== 'pgsql') {
            throw new InvalidArgumentException('Enum CHECK constraints require PostgreSQL.');
        }
    }
}
