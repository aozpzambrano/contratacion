<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Catalogo configurable (tabla estados_proceso). */
class EstadoProceso extends Model
{
    protected $table = 'estados_proceso';

    protected $fillable = ['nombre', 'activo'];
}
