<?php

use Illuminate\Support\Facades\Route;

// Toda la interfaz vive en el panel de Filament (/admin).
Route::redirect('/', '/admin')->name('inicio');

// Descarga autenticada: la autorizacion (permiso + visibilidad del proceso) la resuelve ServicioDocumento.
Route::get('/documentos/versiones/{version}/descargar', \App\Http\Controllers\DescargaDocumentoController::class)
    ->middleware('web')
    ->name('documentos.descargar');
