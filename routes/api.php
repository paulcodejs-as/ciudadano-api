<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CrimeTypeController;
use App\Http\Controllers\Api\V1\DistrictController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MapController;
use App\Http\Controllers\Api\V1\RankingController;
use Illuminate\Support\Facades\Route;

/*
| API v1 de Ciudadano. La consumen la web pública y la futura app Flutter.
| Todas las respuestas usan el formato {success, message, data, meta}.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    // Públicos (solo lectura)
    Route::get('health', HealthController::class)->name('health');
    Route::get('crime-types', [CrimeTypeController::class, 'index'])->name('crime-types.index');

    Route::get('districts', [DistrictController::class, 'index'])->name('districts.index');
    // "locate" va antes de {ubigeo}.
    Route::get('districts/locate', [DistrictController::class, 'locate'])->name('districts.locate');
    Route::get('districts/{ubigeo}', [DistrictController::class, 'show'])
        ->where('ubigeo', '[0-9]{6}')->name('districts.show');

    Route::get('map/districts', [MapController::class, 'districts'])->name('map.districts');
    Route::get('rankings', [RankingController::class, 'index'])->name('rankings.index');

    // Autenticación de administradores (Sanctum)
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
            // Prompt 5: importaciones (POST/GET imports)
        });
    });
});
