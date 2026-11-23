<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TipoDocumento extends Model
{
    protected $table = 'tipos_documento';

    protected $fillable = [
        'codigo', 'nombre', 'fase_id', 'etapa_id', 'rol_generador_id', 'rol_revisor_id',
        'requiere_revision', 'requiere_aprobacion', 'requiere_legalizacion',
        'extensiones_permitidas', 'tamano_maximo_mb', 'permite_multiples', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'requiere_revision' => 'boolean',
            'requiere_aprobacion' => 'boolean',
            'requiere_legalizacion' => 'boolean',
            'permite_multiples' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function fase(): BelongsTo
    {
        return $this->belongsTo(Fase::class, 'fase_id');
    }

    /** @return list<string> extensiones en minuscula, sin punto */
    public function extensiones(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', strtolower($this->extensiones_permitidas)))));
    }
}
