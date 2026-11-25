<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Oferta extends Model
{
    protected $table = 'ofertas';

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function publicacion(): BelongsTo
    {
        return $this->belongsTo(Publicacion::class, 'publicacion_id');
    }

    public function estadoOferta(): BelongsTo
    {
        return $this->belongsTo(EstadoOferta::class, 'estado_oferta_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OfertaItem::class, 'oferta_id');
    }
}
