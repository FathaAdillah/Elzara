<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\Infrastructure\Database\EnumCheckConstraint;
use App\Platform\StateMachine\Axis;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

enum QuotedCheckStatus: string
{
    case Quoted = "owner's \\ status";
    case Plain = 'plain';
}

enum EmptyCheckStatus: string {}

final class EnumCheckConstraintTest extends TestCase
{
    private const TABLE = 'enum_check_fixture';

    private EnumCheckConstraint $checks;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE TEMPORARY TABLE enum_check_fixture (
            order_status varchar NOT NULL DEFAULT \'awaiting_payment\',
            payment_status varchar NOT NULL DEFAULT \'unpaid\',
            fulfillment_status varchar NOT NULL DEFAULT \'unprocessed\',
            procurement_status varchar NOT NULL DEFAULT \'not_required\'
        )');
        $this->checks = new EnumCheckConstraint(DB::connection());
        foreach (Axis::cases() as $axis) {
            $this->checks->addAxis(self::TABLE, $axis);
        }
    }

    protected function tearDown(): void
    {
        try {
            DB::statement('DROP TABLE IF EXISTS enum_check_fixture');
        } finally {
            parent::tearDown();
        }
    }

    public function test_every_enum_case_is_accepted_and_invalid_or_empty_values_violate_each_check(): void
    {
        foreach (Axis::cases() as $axis) {
            foreach ($axis->enumClass()::cases() as $case) {
                $this->assertTrue(DB::table(self::TABLE)->insert([$axis->value => $case->value]));
            }

            foreach (['invalid_status', ''] as $value) {
                $this->assertCheckViolation($axis, $value);
            }
        }
    }

    public function test_catalog_constraint_values_match_enum_cases_without_drift(): void
    {
        foreach (Axis::cases() as $axis) {
            $constraint = DB::selectOne(
                'SELECT pg_get_constraintdef(oid) AS definition, convalidated FROM pg_constraint
                 WHERE conrelid = ?::regclass AND conname = ? AND contype = \'c\'',
                [self::TABLE, EnumCheckConstraint::name(self::TABLE, $axis->value)],
            );
            $this->assertNotNull($constraint);
            $this->assertTrue($constraint->convalidated);
            preg_match_all("/'((?:[^']|'')*)'/", $constraint->definition, $matches);
            $actual = array_map(static fn (string $value): string => str_replace("''", "'", $value), $matches[1]);
            $expected = array_column($axis->enumClass()::cases(), 'value');
            sort($actual);
            sort($expected);
            $this->assertSame($expected, $actual);
        }
    }

    public function test_repeated_add_and_drop_are_safe_and_drop_removes_enforcement(): void
    {
        foreach (Axis::cases() as $axis) {
            $this->checks->addAxis(self::TABLE, $axis);
            $this->assertCheckViolation($axis, 'invalid_status');
            $this->checks->dropAxis(self::TABLE, $axis);
            $this->checks->dropAxis(self::TABLE, $axis);
            $this->assertTrue(DB::table(self::TABLE)->insert([$axis->value => 'invalid_status']));
        }
    }

    public function test_existing_invalid_data_is_not_silently_accepted(): void
    {
        $this->checks->dropAxis(self::TABLE, Axis::Order);
        DB::table(self::TABLE)->insert(['order_status' => 'legacy_invalid']);

        try {
            $this->checks->addAxis(self::TABLE, Axis::Order);
            $this->fail('Existing invalid data must be backfilled before adding a validated CHECK.');
        } catch (QueryException $failure) {
            $this->assertSame('23514', $failure->errorInfo[0]);
        }
    }

    public function test_identifiers_and_enum_values_are_quoted_safely(): void
    {
        $table = 'enum "quoted fixture';
        $column = 'status "quoted';
        $grammar = DB::connection()->getQueryGrammar();
        DB::statement('CREATE TEMPORARY TABLE '.$grammar->wrapTable($table).' ('.$grammar->wrap($column).' varchar NOT NULL)');

        try {
            $this->checks->add($table, $column, QuotedCheckStatus::class);
            foreach (QuotedCheckStatus::cases() as $case) {
                $this->assertTrue(DB::table($table)->insert([$column => $case->value]));
            }
            $this->checks->drop($table, $column);
            $this->assertTrue(DB::table($table)->insert([$column => 'anything']));
        } finally {
            DB::statement('DROP TABLE '.$grammar->wrapTable($table));
        }

        $this->assertLessThanOrEqual(63, strlen(EnumCheckConstraint::name(str_repeat('long', 40), $column)));
        $this->assertNotSame(EnumCheckConstraint::name('a_b', 'c'), EnumCheckConstraint::name('a', 'b_c'));
    }

    public function test_empty_enum_is_rejected_without_issuing_invalid_sql(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->checks->add(self::TABLE, 'order_status', EmptyCheckStatus::class);
    }

    private function assertCheckViolation(Axis $axis, string $value): void
    {
        try {
            DB::table(self::TABLE)->insert([$axis->value => $value]);
            $this->fail('Expected an enum CHECK violation.');
        } catch (QueryException $failure) {
            $this->assertSame('23514', $failure->errorInfo[0]);
            $this->assertStringContainsString(EnumCheckConstraint::name(self::TABLE, $axis->value), $failure->getMessage());
        }
    }
}
