<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    use ApiResponse;

    public function __invoke(): JsonResponse
    {
        return $this->success([
            'app' => config('app.name'),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'database' => DB::selectOne('SELECT VERSION() AS v')->v,
        ], 'Servicio operativo.');
    }
}
