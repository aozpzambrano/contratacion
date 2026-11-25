<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Documento que debe existir (en un estado minimo) para poder ejecutar una transicion del proceso. */
class RequisitoTransicion extends Model
{
    public $timestamps = false;

    protected $table = 'transicion_proceso_requisitos';

    protected $fillable = ['transicion_id', 'tipo_documento_id', 'estado_documento_minimo_id'];

    public function transicion(): BelongsTo
    {
        return $this->belongsTo(TransicionProceso::class, 'transicion_id');
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
    }

    public function estadoMinimo(): BelongsTo
    {
        return $this->belongsTo(EstadoDocumento::class, 'estado_documento_minimo_id');
    }
}
