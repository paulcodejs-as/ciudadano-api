<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /**
     * Inicia sesión de un administrador y devuelve un token Sanctum.
     * Solo los usuarios con is_admin pueden obtener token por ahora.
     *
     * @return array{user: User, token: string}|null
     */
    public function loginAdmin(string $email, string $password, ?string $deviceName = null): ?array
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->is_admin || ! Hash::check($password, $user->password)) {
            return null;
        }

        $token = $user->createToken($deviceName ?: 'ciudadano-admin')->plainTextToken;

        return compact('user', 'token');
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }
}
