<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Publicacion del proceso (hasta el numero solicitado por la unidad requirente). */
class Publicacion extends Model
{
    protected $table = 'publicaciones';

    protected function casts(): array
    {
        return ['fecha_publicacion' => 'datetime', 'fecha_limite' => 'datetime', 'ofertas_registradas_en' => 'datetime'];
    }

    public function proceso(): BelongsTo
    {
        return $this->belongsTo(Proceso::class, 'proceso_id');
    }

    /** "Necesidad de Contratacion Publicacion" (texto libre). */
    public function necesidad(): string
    {
        return (string) $this->codigo_nc;
    }
}
