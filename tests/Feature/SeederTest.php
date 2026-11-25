<?php

namespace Tests\Feature;

use App\Models\CrimeType;
use App\Models\DataSource;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_create_catalogs_and_admin_idempotently(): void
    {
        config([
            'ciudadano.admin.email' => 'admin@test.pe',
            'ciudadano.admin.password' => 'secreto123',
        ]);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class); // dos veces: no debe duplicar

        $this->assertSame(7, CrimeType::count());
        $this->assertSame(1, DataSource::where('slug', DataSource::SIDPOL)->count());

        $admin = User::where('email', 'admin@test.pe')->sole();
        $this->assertTrue($admin->is_admin);
    }
}
