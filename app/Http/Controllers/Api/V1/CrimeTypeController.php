<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CrimeTypeResource;
use App\Models\CrimeType;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CrimeTypeController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $types = CrimeType::orderBy('name')->get();

        return $this->success(
            CrimeTypeResource::collection($types),
            'Modalidades de denuncia.',
            ['count' => $types->count()],
        );
    }
}
