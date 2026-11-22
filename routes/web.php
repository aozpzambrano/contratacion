<?php

use Illuminate\Support\Facades\Route;

// Toda la interfaz vive en el panel de Filament (/admin).
Route::redirect('/', '/admin')->name('inicio');
