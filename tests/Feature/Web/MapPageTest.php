<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\V1\StatsTestData;
use Tests\TestCase;

class MapPageTest extends TestCase
{
    use RefreshDatabase, StatsTestData;

    public function test_map_page_renders_filters_and_years(): void
    {
        $this->withoutVite();
        $this->seedStats(); // datos de ene–feb 2024

        $this->get('/')
            ->assertOk()
            ->assertSee('Ciudadano')
            ->assertSee('<option value="robo">Robo</option>', false)
            ->assertSee('2024 (ene–feb)')
            ->assertSee('Tasa por 10 000 hab.')
            ->assertSee('"apiBase":', false)
            ->assertDontSee('Todavía no hay denuncias importadas');
    }

    public function test_map_page_warns_without_data(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('Todavía no hay denuncias importadas');
    }
}
