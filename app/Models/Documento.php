<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Documento extends Model
{
    use SoftDeletes;

    protected $table = 'documentos';

    /** Estados en los que la unidad requirente puede cargar una version nueva (el proceso debe ser editable). */
    public const ESTADOS_VERSION_UNIDAD = ['BORRADOR', 'CON_OBSERVACIONES', 'EN_CORRECCION'];

    /** Estados en los que el analista asignado puede subir una version corregida (el estado se conserva). */
    public const ESTADOS_VERSION_ANALISTA = ['PENDIENTE_REVISION', 'EN_REVISION', 'CON_OBSERVACIONES', 'EN_CORRECCION'];

    // El estado y las versiones solo cambian por el servicio / las transiciones de la BD.
    protected $fillable = ['titulo', 'numero_documento', 'fecha_documento'];

    public function proceso(): BelongsTo
    {
        return $this->belongsTo(Proceso::class, 'proceso_id');
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
    }

    public function estadoDocumento(): BelongsTo
    {
        return $this->belongsTo(EstadoDocumento::class, 'estado_documento_id');
    }

    public function versionActual(): BelongsTo
    {
        return $this->belongsTo(DocumentoVersion::class, 'version_actual_id');
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(DocumentoVersion::class, 'documento_id')->orderByDesc('numero_version');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /** Ultima legalizacion (PDF firmado) del documento; la anterior queda como respaldo. */
    public function legalizacionVigente(): ?Legalizacion
    {
        return Legalizacion::query()
            ->whereIn('documento_version_id', DocumentoVersion::query()->where('documento_id', $this->id)->select('id'))
            ->whereNotNull('archivo_id')
            ->orderByDesc('id')
            ->first();
    }

}
