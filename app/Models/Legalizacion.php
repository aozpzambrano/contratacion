<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro de cada legalizacion (PDF firmado electronicamente) de una version de documento. */
class Legalizacion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'legalizaciones';

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentoVersion::class, 'documento_version_id');
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
