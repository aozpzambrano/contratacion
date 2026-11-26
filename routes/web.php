<?php

use Illuminate\Support\Facades\Route;

// Toda la interfaz vive en el panel de Filament (/admin).
Route::redirect('/', '/admin')->name('inicio');

// Descarga autenticada: la autorizacion (permiso + visibilidad del proceso) la resuelve ServicioDocumento.
Route::get('/documentos/versiones/{version}/descargar', \App\Http\Controllers\DescargaDocumentoController::class)
    ->middleware('web')
    ->name('documentos.descargar');

// PDF legalizado (firmado electronicamente) vigente de un documento.
Route::get('/documentos/{documento}/legalizado/descargar', \App\Http\Controllers\DescargaLegalizadoController::class)
    ->middleware('web')
    ->name('documentos.descargar_legalizado');


// Todos los archivos de una oferta en un ZIP.
Route::get('/ofertas/{oferta}/descargar-zip', \App\Http\Controllers\DescargaOfertaZipController::class)
    ->whereNumber('oferta')
    ->middleware('web')
    ->name('ofertas.descargar_zip');
