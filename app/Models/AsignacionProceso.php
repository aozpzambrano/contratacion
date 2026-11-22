<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsignacionProceso extends Model
{
    protected $table = 'asignaciones_proceso';

    protected $fillable = [
        'proceso_id', 'user_id', 'rol_id', 'fase_id', 'etapa_id', 'actividad_id',
        'asignado_por', 'fecha_inicio', 'fecha_fin', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'fecha_inicio' => 'datetime', 'fecha_fin' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }
}
