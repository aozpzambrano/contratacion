<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstadoDocumento extends Model
{
    protected $table = 'estados_documento';

    protected function casts(): array
    {
        return ['es_inicial' => 'boolean', 'es_final' => 'boolean', 'activo' => 'boolean'];
    }
}
