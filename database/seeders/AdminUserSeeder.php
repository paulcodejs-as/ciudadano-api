<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/** Crea o actualiza el administrador con config/ciudadano.php (ADMIN_* del .env). */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('ciudadano.admin.email');
        $password = config('ciudadano.admin.password');

        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL o ADMIN_PASSWORD vacíos en .env: no se creó el administrador.');

            return;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => config('ciudadano.admin.name', 'Administrador'),
            'password' => $password, // el cast "hashed" la cifra
            'is_admin' => true,
        ])->save();
    }
}
