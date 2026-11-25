<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Documento fijo de un tipo de proceso (reglamento, anexos, proyecto de orden de compra...).
 * El administrador carga y reemplaza el archivo; cada reemplazo queda como version de respaldo.
 */
class DocumentoFijo extends Model
{
    protected $table = 'documentos_fijos';

    // El archivo vigente solo lo cambia ServicioDocumentoFijo (deja la version de respaldo).
    protected $fillable = ['nombre', 'descripcion', 'activo'];

    protected function casts(): array
    {
        return ['es_plantilla' => 'boolean', 'activo' => 'boolean'];
    }

    public function tipoProceso(): BelongsTo
    {
        return $this->belongsTo(TipoProceso::class, 'tipo_proceso_id');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(Archivo::class, 'archivo_id');
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(DocumentoFijoVersion::class, 'documento_fijo_id')->orderByDesc('numero_version');
    }

    /** @return array<int, string> */
    public function extensiones(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', strtolower($this->extensiones_permitidas)))));
    }
}
