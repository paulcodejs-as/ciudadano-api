<?php

namespace Tests\Feature\Api\V1;

use Database\Seeders\CrimeTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrimeTypesTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_the_seven_sidpol_crime_types(): void
    {
        $this->seed(CrimeTypeSeeder::class);

        $this->getJson('/api/v1/crime-types')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.count', 7)
            ->assertJsonCount(7, 'data')
            ->assertJsonStructure(['success', 'message', 'data' => [['slug', 'name', 'color']], 'meta']);
    }
}
