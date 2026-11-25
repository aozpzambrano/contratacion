<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Respaldo historico de un documento fijo (solo se agrega; la base impide modificarlo). */
class DocumentoFijoVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'documentos_fijos_versiones';

    public function documentoFijo(): BelongsTo
    {
        return $this->belongsTo(DocumentoFijo::class, 'documento_fijo_id');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(Archivo::class, 'archivo_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
