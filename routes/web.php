<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::view('/', 'home')->name('home');

    // Cada ruta exige el permiso exacto: recurso.accion
    Route::prefix('usuarios')->name('usuarios.')->controller(UsuarioController::class)->group(function () {
        Route::get('/', 'index')->middleware('permission:adm.usuarios.ver')->name('index');
        Route::get('/nuevo', 'create')->middleware('permission:adm.usuarios.crear')->name('create');
        Route::post('/', 'store')->middleware('permission:adm.usuarios.crear')->name('store');
        Route::get('/{usuario}/editar', 'edit')->middleware('permission:adm.usuarios.editar')->name('edit');
        Route::put('/{usuario}', 'update')->middleware('permission:adm.usuarios.editar')->name('update');
    });

    Route::prefix('roles')->name('roles.')->controller(RolController::class)->group(function () {
        Route::get('/', 'index')->middleware('permission:adm.roles.ver')->name('index');
        Route::get('/nuevo', 'create')->middleware('permission:adm.roles.crear')->name('create');
        Route::post('/', 'store')->middleware('permission:adm.roles.crear')->name('store');
        Route::get('/{rol}/editar', 'edit')->middleware('permission:adm.roles.editar')->name('edit');
        Route::put('/{rol}', 'update')->middleware('permission:adm.roles.editar')->name('update');
    });

    Route::get('/reportes/usuarios.csv', [ReporteController::class, 'usuariosCsv'])
        ->middleware('permission:rep.usuarios.exportar')->name('reportes.usuarios.csv');
});