<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Solicitud de publicacion que la unidad requirente registra al remitir los documentos legalizados. */
class SolicitudPublicacion extends Model
{
    protected $table = 'solicitudes_publicacion';

    protected function casts(): array
    {
        return ['fecha_remision' => 'datetime', 'fecha_devolucion' => 'datetime'];
    }

    public function proceso(): BelongsTo
    {
        return $this->belongsTo(Proceso::class, 'proceso_id');
    }

    public function memorando(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'memorando_documento_id');
    }

    public function remitente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remitida_por');
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(SolicitudPublicacionDetalle::class, 'solicitud_id')->orderBy('numero_publicacion');
    }

    /** "1.ª: 24 h (1 dia) · 2.ª: 48 h (2 dias)" */
    public function resumenPublicaciones(): string
    {
        return $this->detalle->map(
            fn ($d) => "{$d->numero_publicacion}.ª: ".self::textoHoras((int) $d->horas)
        )->implode(' · ');
    }

    public static function textoHoras(int $horas): string
    {
        $equivalencia = $horas % 24 === 0 ? ' ('.($horas / 24).' '.($horas / 24 === 1 ? 'día' : 'días').')' : '';

        return "{$horas} h{$equivalencia}";
    }
}
