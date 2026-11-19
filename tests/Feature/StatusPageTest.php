<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

class StatusPageTest extends TestCase
{
    public function test_it_reports_a_working_postgresql_connection(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('Aplikasi berjalan')
            ->assertSee('PostgreSQL terhubung');
    }

    public function test_it_returns_service_unavailable_when_postgresql_cannot_be_reached(): void
    {
        $this->withoutVite();
        Exceptions::fake(QueryException::class);
        config(['database.connections.pgsql.database' => 'gigkampus_phase1_missing_database']);
        DB::purge('pgsql');

        $this->get('/')
            ->assertServiceUnavailable()
            ->assertSee('PostgreSQL tidak tersedia')
            ->assertDontSee('gigkampus_phase1_missing_database');

        Exceptions::assertReported(QueryException::class);
    }
}
