<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Catalogo configurable (tabla estados_macro). */
class EstadoMacro extends Model
{
    protected $table = 'estados_macro';

    protected $fillable = ['nombre', 'activo'];
}
