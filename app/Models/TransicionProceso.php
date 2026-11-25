<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransicionProceso extends Model
{
    protected $table = 'transiciones_proceso';

    protected function casts(): array
    {
        return ['requiere_comentario' => 'boolean', 'activo' => 'boolean', 'validaciones' => 'array'];
    }

    public function permiso(): BelongsTo
    {
        return $this->belongsTo(Permiso::class, 'permiso_id');
    }

    public function estadoOrigen(): BelongsTo
    {
        return $this->belongsTo(EstadoProceso::class, 'estado_origen_id');
    }

    public function estadoDestino(): BelongsTo
    {
        return $this->belongsTo(EstadoProceso::class, 'estado_destino_id');
    }
}
