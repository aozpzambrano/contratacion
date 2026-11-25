<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfertaItem extends Model
{
    protected $table = 'oferta_items';

    public function procesoItem(): BelongsTo
    {
        return $this->belongsTo(ProcesoItem::class, 'proceso_item_id');
    }
}
