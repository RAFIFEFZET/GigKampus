<?php

namespace Tests\Feature;

use Tests\PostgresTestCase;

class StatusPageTest extends PostgresTestCase
{
    public function test_beranda_replaces_the_phase_one_status_page(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('GigKampus')
            ->assertSee('Jelajahi Jasa')
            ->assertDontSee('PostgreSQL terhubung');
    }

    public function test_operational_health_route_still_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}
