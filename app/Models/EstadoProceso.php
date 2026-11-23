<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Catalogo configurable (tabla estados_proceso). */
class EstadoProceso extends Model
{
    protected $table = 'estados_proceso';

    protected $fillable = ['nombre', 'activo'];

    protected function casts(): array
    {
        return [
            'es_inicial' => 'boolean',
            'es_final' => 'boolean',
            'permite_asignar_codigo' => 'boolean',
            'activo' => 'boolean',
        ];
    }
}
