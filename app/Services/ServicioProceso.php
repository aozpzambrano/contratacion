<?php

namespace App\Services;

use App\Models\AsignacionProceso;
use App\Models\Fase;
use App\Models\Proceso;
use App\Models\Rol;
use App\Models\TransicionProceso;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Acciones sobre un proceso. Las reglas de negocio viven en la base de datos
 * (aplicar_transicion_proceso, asignar_codigo_proceso); aqui solo se decide que
 * mostrar a cada usuario y se traducen los errores de la base a mensajes legibles.
 */
class ServicioProceso
{
    public function estaAsignado(Proceso $proceso, User $usuario): bool
    {
        return $proceso->asignaciones()->where('user_id', $usuario->id)->where('activo', true)->exists();
    }

    /** Transiciones que el usuario puede ejecutar ahora: permiso, fase del rol y asignacion. */
    public function transicionesDisponibles(Proceso $proceso, User $usuario): Collection
    {
        if (! $usuario->activo) {
            return collect();
        }

        $faseId = $proceso->estadoMacro?->fase_id;
        $fase = $faseId ? Fase::whereKey($faseId)->value('codigo') : null;
        $asignado = $this->estaAsignado($proceso, $usuario);

        return TransicionProceso::with(['permiso', 'estadoDestino'])
            ->where('activo', true)
            ->where('estado_origen_id', $proceso->estado_proceso_id)
            ->orderBy('id')
            ->get()
            ->filter(function (TransicionProceso $t) use ($usuario, $asignado, $fase) {
                $codigo = $t->permiso->codigo;

                return $usuario->tienePermiso($codigo)
                    && (! $t->permiso->requiere_asignacion || $asignado)
                    && ($fase === null || $usuario->tienePermisoEnFase($codigo, $fase));
            })
            ->values();
    }

    public function aplicarTransicion(Proceso $proceso, TransicionProceso $transicion, User $usuario, ?string $comentario = null): void
    {
        $comentario = filled($comentario) ? trim($comentario) : null;

        $this->ejecutar(fn () => DB::select(
            'select aplicar_transicion_proceso(?, ?, ?, ?)',
            [$proceso->id, $transicion->codigo, $usuario->id, $comentario]
        ));
    }

    public function puedeGenerarCodigo(Proceso $proceso, User $usuario): bool
    {
        return $proceso->codigo === null
            && $proceso->estadoProceso?->permite_asignar_codigo
            && $usuario->tienePermiso('procesos.asignar_codigo')
            && $this->estaAsignado($proceso, $usuario);
    }

    public function generarCodigo(Proceso $proceso, User $usuario): string
    {
        return $this->ejecutar(fn () => DB::selectOne(
            'select asignar_codigo_proceso(?, ?) as codigo',
            [$proceso->id, $usuario->id]
        )->codigo);
    }

    /**
     * Asignar analista solo procede cuando la unidad requirente ya envio el proceso a revision:
     * no en el estado inicial ni en los finales (se usan las marcas es_inicial / es_final del catalogo).
     */
    public function puedeAsignarAnalista(Proceso $proceso, User $usuario): bool
    {
        $estado = $proceso->estadoProceso;

        return $usuario->tienePermiso('procesos.asignar_responsables')
            && $estado !== null
            && ! $estado->es_inicial
            && ! $estado->es_final;
    }

    /** Analistas de Compras activos (candidatos a responsable del proceso). */
    public function analistasDisponibles(): Collection
    {
        return User::query()
            ->where('activo', true)
            ->whereHas('roles', fn ($query) => $query->where('roles.codigo', 'COMPRAS_ANALISTA')->where('roles.activo', true))
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    /**
     * El jefe de Compras asigna al analista que revisa y sigue el proceso.
     * Con $reemplazar se cierran las asignaciones vigentes de analista (p. ej. otra persona para publicar).
     */
    public function asignarAnalista(Proceso $proceso, User $analista, User $jefe, bool $reemplazar = true): void
    {
        if (! $jefe->tienePermiso('procesos.asignar_responsables')) {
            throw new DomainException('No tiene permiso para asignar responsables.');
        }
        if (! $this->puedeAsignarAnalista($proceso, $jefe)) {
            throw new DomainException('Solo se puede asignar analista despues de que la unidad requirente envie el proceso a revision.');
        }
        if (! $analista->activo || ! $analista->tieneRol('COMPRAS_ANALISTA')) {
            throw new DomainException('El usuario elegido no es un analista de Compras activo.');
        }

        $rolId = Rol::where('codigo', 'COMPRAS_ANALISTA')->value('id');
        $faseId = $proceso->estadoMacro?->fase_id ?? Fase::where('codigo', 'PREPARATORIA')->value('id');

        DB::transaction(function () use ($proceso, $analista, $jefe, $reemplazar, $rolId, $faseId) {
            if ($reemplazar) {
                $proceso->asignaciones()
                    ->where('rol_id', $rolId)->where('activo', true)->where('user_id', '!=', $analista->id)
                    ->update([
                        'activo' => false,
                        // hora de la base y nunca anterior al inicio (restriccion fecha_fin >= fecha_inicio)
                        'fecha_fin' => DB::raw('GREATEST(now(), fecha_inicio)'),
                        'updated_at' => DB::raw('now()'),
                    ]);
            }

            $yaAsignado = $proceso->asignaciones()
                ->where('rol_id', $rolId)->where('user_id', $analista->id)->where('activo', true)->exists();

            if (! $yaAsignado) {
                AsignacionProceso::create([
                    'proceso_id' => $proceso->id,
                    'user_id' => $analista->id,
                    'rol_id' => $rolId,
                    'fase_id' => $faseId,
                    'asignado_por' => $jefe->id,
                ]);
            }

            DB::table('auditoria')->insert([
                'user_id' => $jefe->id,
                'evento' => 'proceso.analista_asignado',
                'entidad' => 'procesos',
                'entidad_id' => $proceso->id,
                'valores_nuevos' => json_encode(['analista_id' => $analista->id, 'reemplaza' => $reemplazar]),
            ]);
        });
    }

    /** Ejecuta dentro de una transaccion (savepoint si ya hay una) y traduce el error de la base. */
    private function ejecutar(callable $accion): mixed
    {
        try {
            return DB::transaction($accion);
        } catch (QueryException $e) {
            throw new DomainException($this->mensajeDeBase($e), 0, $e);
        }
    }

    private function mensajeDeBase(QueryException $e): string
    {
        $mensaje = ($e->getPrevious() ?? $e)->getMessage();

        if (preg_match('/ERROR:\s+(.+?)(?:\R|$)/s', $mensaje, $m)) {
            return trim($m[1]);
        }

        return 'No se pudo completar la acción.';
    }
}
