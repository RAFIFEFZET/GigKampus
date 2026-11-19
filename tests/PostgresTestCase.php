<?php

namespace Tests;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use stdClass;

abstract class PostgresTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    protected function row(string $table, array $where = []): stdClass
    {
        $row = DB::table($table)->where($where)->orderBy('id')->first();
        self::assertNotNull($row, "Missing demo row in {$table}. Run the migrations and demo seeder before this suite.");

        return $row;
    }

    protected function copyRow(stdClass $row, array $changes = []): array
    {
        $attributes = (array) $row;
        unset($attributes['id']);

        return array_replace($attributes, $changes);
    }

    protected function assertSqlState(string $expected, Closure $write, ?Connection $connection = null): void
    {
        $connection ??= DB::connection();

        try {
            $connection->transaction(fn () => $write($connection));
        } catch (QueryException $exception) {
            self::assertSame($expected, $exception->errorInfo[0], $exception->getMessage());

            return;
        }

        self::fail("PostgreSQL unexpectedly accepted a write that should raise {$expected}.");
    }
}
