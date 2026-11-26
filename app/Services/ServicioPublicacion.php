<?php

namespace App\Services;

use App\Models\Proceso;
use App\Models\TransicionProceso;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Publicacion del proceso (analista de Compras, estado "Para publicar"): registra los datos de la publicacion
 * y aplica la transicion "Publicar". El tope de publicaciones es el numero que solicito la unidad requirente
 * (lo impone un disparador de la base).
 */
class ServicioPublicacion
{
    public const MAX_NECESIDAD = 60;

    public function __construct(private readonly ServicioProceso $procesos) {}

    public function puedePublicar(Proceso $proceso, User $usuario): bool
    {
        return $this->transicion($proceso, $usuario) !== null;
    }

    private function transicion(Proceso $proceso, User $usuario): ?TransicionProceso
    {
        // Publica el analista de Compras asignado al proceso (la base tambien lo exige: requiere_responsable).
        if (! $this->procesos->estaAsignado($proceso, $usuario)) {
            return null;
        }

        // "Publicar" la primera vez; "Republicar" cuando no se alcanzo el numero de ofertas y la unidad pidio mas publicaciones.
        $disponibles = $this->procesos->transicionesDisponibles($proceso, $usuario);

        return $disponibles->firstWhere('codigo', 'publicar')
            ?? ($this->puedeRepublicar($proceso) ? $disponibles->firstWhere('codigo', 'republicar') : null);
    }

    /** Numero de publicaciones que pidio la unidad en la solicitud vigente. */
    public function publicacionesSolicitadas(Proceso $proceso): int
    {
        return (int) $proceso->solicitudRemitida()?->detalle->count();
    }

    /** Decision del flujo: faltan ofertas (total del ciclo < minimo) y aun quedan publicaciones por hacer. */
    public function puedeRepublicar(Proceso $proceso): bool
    {
        return app(ServicioOferta::class)->decision($proceso) === 'republicar';
    }

    public function registrar(Proceso $proceso, User $usuario, string $necesidad, string $enlace, string $fechaPublicacion, string $fechaLimite): void
    {
        $transicion = $this->transicion($proceso, $usuario)
            ?? throw new DomainException('No puede publicar este proceso en este momento.');

        $necesidad = trim($necesidad);
        $enlace = trim($enlace);
        if ($necesidad === '') {
            throw new DomainException('Ingrese la Necesidad de Contratación de la publicación.');
        }
        if (mb_strlen($necesidad) > self::MAX_NECESIDAD) {
            throw new DomainException('La Necesidad de Contratación no puede superar '.self::MAX_NECESIDAD.' caracteres.');
        }
        if ($enlace === '' || ! filter_var($enlace, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $enlace)) {
            throw new DomainException('Ingrese un link de publicación válido (http:// o https://).');
        }
        $desde = $this->fecha($fechaPublicacion, 'la Fecha y hora de Publicación');
        $hasta = $this->fecha($fechaLimite, 'la Fecha y hora Límite');
        if ($hasta->lte($desde)) {
            throw new DomainException('La Fecha Límite debe ser posterior a la Fecha de Publicación.');
        }

        try {
            DB::transaction(function () use ($proceso, $usuario, $transicion, $necesidad, $enlace, $desde, $hasta) {
                // "Publicar" de nuevo es una decision sobre las ofertas de la publicacion anterior: la base la evalua
                // antes de existir la nueva publicacion (que todavia no tiene ofertas). La primera publicacion si
                // debe existir antes de la transicion.
                $esRepublicacion = $transicion->codigo === 'republicar';
                if ($esRepublicacion) {
                    $this->procesos->aplicarTransicion($proceso, $transicion, $usuario);
                }

                $secuencia = (int) DB::table('publicaciones')
                    ->where('proceso_id', $proceso->id)->where('ciclo', $proceso->ciclo)->max('secuencia') + 1;

                $id = DB::table('publicaciones')->insertGetId([
                    'proceso_id' => $proceso->id,
                    'ciclo' => $proceso->ciclo,
                    'secuencia' => $secuencia,
                    'codigo_nc' => $necesidad,
                    'enlace' => $enlace,
                    'fecha_publicacion' => $desde->toIso8601String(),
                    'fecha_limite' => $hasta->toIso8601String(),
                ]);

                DB::table('auditoria')->insert([
                    'user_id' => $usuario->id,
                    'evento' => 'proceso.publicacion_registrada',
                    'entidad' => 'publicaciones',
                    'entidad_id' => $id,
                    'valores_nuevos' => json_encode([
                        'proceso_id' => $proceso->id, 'secuencia' => $secuencia, 'enlace' => $enlace,
                        'fecha_publicacion' => $desde->toIso8601String(), 'fecha_limite' => $hasta->toIso8601String(),
                    ]),
                ]);

                if (! $esRepublicacion) {
                    $this->procesos->aplicarTransicion($proceso, $transicion, $usuario);
                }
            });
        } catch (QueryException $e) {
            $mensaje = ($e->getPrevious() ?? $e)->getMessage();

            throw new DomainException(preg_match('/ERROR:\s+(.+?)(?:\R|$)/s', $mensaje, $m) ? trim($m[1]) : 'No se pudo registrar la publicación.', 0, $e);
        }
    }

    /** Fecha y hora (Y-m-d H:i[:s]) en la zona horaria de la aplicacion. */
    private function fecha(string $valor, string $nombre): Carbon
    {
        $valor = trim($valor);
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?/', $valor, $m)) {
            throw new DomainException("Ingrese {$nombre} (fecha y hora).");
        }
        $texto = $m[1].' '.$m[2].(($m[3] ?? '') ?: ':00');
        $fecha = Carbon::createFromFormat('Y-m-d H:i:s', $texto);
        if (! $fecha || $fecha->format('Y-m-d H:i:s') !== $texto) {
            throw new DomainException("Ingrese {$nombre} (fecha y hora válidas).");
        }

        return $fecha;
    }
}
