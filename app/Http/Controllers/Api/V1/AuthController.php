<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private AuthService $authService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->loginAdmin(
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('device_name'),
        );

        if (! $result) {
            return $this->error('Credenciales incorrectas.', 401);
        }

        return $this->success([
            'token' => $result['token'],
            'user' => new UserResource($result['user']),
        ], 'Sesión iniciada.');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->success(null, 'Sesión cerrada.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(new UserResource($request->user()));
    }
}
