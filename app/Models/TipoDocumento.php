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
        'extensiones_permitidas', 'tamano_maximo_mb', 'permite_multiples', 'editable_tras_devolucion', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'requiere_revision' => 'boolean',
            'requiere_aprobacion' => 'boolean',
            'requiere_legalizacion' => 'boolean',
            'permite_multiples' => 'boolean',
            'editable_tras_devolucion' => 'boolean',
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

    /** Tipos MIME que corresponden a las extensiones permitidas (para limitar el selector de archivos). */
    public function mimesPermitidos(): array
    {
        $mimes = [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
        ];

        return array_values(array_unique(array_merge(...array_map(fn ($ext) => $mimes[$ext] ?? [], $this->extensiones()))));
    }

    /** "PDF, Word" / "PDF, Word o Excel" segun las extensiones permitidas. */
    public function formatosLegibles(): string
    {
        $nombres = ['pdf' => 'PDF', 'doc' => 'Word', 'docx' => 'Word', 'xls' => 'Excel', 'xlsx' => 'Excel', 'jpg' => 'JPG', 'jpeg' => 'JPG', 'png' => 'PNG', 'zip' => 'ZIP'];
        $lista = array_values(array_unique(array_map(fn ($ext) => $nombres[$ext] ?? strtoupper($ext), $this->extensiones())));

        return count($lista) > 1 ? implode(', ', array_slice($lista, 0, -1)).' o '.end($lista) : ($lista[0] ?? '');
    }
}
