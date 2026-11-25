<?php

use App\Http\Controllers\Web\Admin\ImportController;
use App\Http\Controllers\Web\Admin\LoginController;
use App\Http\Controllers\Web\MapController;
use Illuminate\Support\Facades\Route;

/*
| Web pública (Blade). El mapa pide sus datos a /api/v1.
*/

Route::get('/', [MapController::class, 'index'])->name('map.index');

/*
| Panel de administración (sesión web + is_admin).
*/

Route::prefix('admin')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::post('login', [LoginController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
    });

    Route::middleware(['auth', 'admin'])->name('admin.')->group(function () {
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
        Route::redirect('/', '/admin/imports')->name('home');
        Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
        Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:10,1')->name('imports.store');
        Route::get('imports/{import}', [ImportController::class, 'show'])->whereNumber('import')->name('imports.show');
    });
});
