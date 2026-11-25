<?php

use App\Http\Controllers\Web\MapController;
use Illuminate\Support\Facades\Route;

/*
| Web pública (Blade). El mapa pide sus datos a /api/v1.
*/

Route::get('/', [MapController::class, 'index'])->name('map.index');
