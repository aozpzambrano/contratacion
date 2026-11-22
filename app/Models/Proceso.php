<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Proceso extends Model
{
    use SoftDeletes;

    protected $table = 'procesos';

    /**
     * Los estados (estado_macro_id, estado_proceso_id) y el ciclo NO son editables a mano:
     * solo cambian con las transiciones (aplicar_transicion_proceso) y un trigger lo impone.
     */
    protected $fillable = [
        'tipo_proceso_id', 'descripcion_producto', 'codigo_cpc', 'observacion',
    ];

    /** Roles que ven todos los procesos. El resto ve solo los asignados (y la unidad requirente, los que creo). */
    public const ROLES_VEN_TODOS = ['ADMIN', 'CONSULTA', 'COMPRAS_JEFE'];

    /** Estados en los que se pueden corregir los datos del proceso (supuesto de la Entrega D1). */
    public const ESTADOS_EDITABLES = ['INICIO', 'EN_CORRECCION'];

    public function tipoProceso(): BelongsTo
    {
        return $this->belongsTo(TipoProceso::class, 'tipo_proceso_id');
    }

    public function estadoMacro(): BelongsTo
    {
        return $this->belongsTo(EstadoMacro::class, 'estado_macro_id');
    }

    public function estadoProceso(): BelongsTo
    {
        return $this->belongsTo(EstadoProceso::class, 'estado_proceso_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProcesoItem::class, 'proceso_id')->orderBy('numero_linea');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(AsignacionProceso::class, 'proceso_id');
    }

    public function historial(): HasMany
    {
        return $this->hasMany(HistorialEstadoProceso::class, 'proceso_id')->orderBy('id');
    }

    public function esEditable(): bool
    {
        return in_array($this->estadoProceso?->codigo, self::ESTADOS_EDITABLES, true);
    }

    /**
     * Visibilidad:
     * - Admin, Consulta y Jefe de Compras: todos.
     * - Analista de Compras y demas roles: solo donde tienen una asignacion activa.
     * - Unidad requirente: ademas, los que creo.
     */
    public function scopeVisiblesPara(Builder $consulta, User $usuario): Builder
    {
        if (! $usuario->activo) {
            return $consulta->whereRaw('false');
        }

        $roles = $usuario->roles()->where('roles.activo', true)->pluck('roles.codigo');

        if ($roles->intersect(self::ROLES_VEN_TODOS)->isNotEmpty()) {
            return $consulta;
        }

        return $consulta->where(function (Builder $q) use ($usuario, $roles) {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))
                ->from('asignaciones_proceso as a')
                ->whereColumn('a.proceso_id', 'procesos.id')
                ->where('a.user_id', $usuario->id)
                ->where('a.activo', true));

            if ($roles->contains('UNIDAD_REQUIRENTE')) {
                $q->orWhere('procesos.creado_por', $usuario->id);
            }
        });
    }
}
