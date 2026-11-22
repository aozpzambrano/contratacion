<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Catalogo configurable (tabla tipos_proceso). */
class TipoProceso extends Model
{
    protected $table = 'tipos_proceso';

    protected $fillable = ['nombre', 'activo'];
}
