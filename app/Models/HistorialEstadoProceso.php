<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Solo lectura: la tabla es append-only (un trigger bloquea UPDATE y DELETE). */
class HistorialEstadoProceso extends Model
{
    protected $table = 'historial_estados_proceso';

    public const UPDATED_AT = null;

    public function transicion(): BelongsTo
    {
        return $this->belongsTo(TransicionProceso::class, 'transicion_id');
    }

    public function estadoDestino(): BelongsTo
    {
        return $this->belongsTo(EstadoProceso::class, 'estado_destino_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
