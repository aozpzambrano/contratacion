<?php

namespace App\Services;

use App\Models\Proceso;
use App\Models\TipoDocumento;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Publicaciones agotadas y 0 ofertas: el analista sube el Memorando de devolucion y el Informe de devolucion
 * (PDF legalizados), el expediente se devuelve, se cierra el ciclo y el proceso queda en "Para Re-publicar"
 * para que la unidad remita de nuevo memorando, horas y numero de publicaciones.
 */
class ServicioDevolucionSinOfertas
{
    public const TIPO_MEMORANDO = 'MEMORANDO_DEVOLUCION';

    public const TIPO_INFORME = 'INFORME_DEVOLUCION_SIN_OFERTAS';

    public function __construct(
        private readonly ServicioProceso $procesos,
        private readonly ServicioDocumento $documentos,
        private readonly ServicioOferta $ofertas,
    ) {}

    public function puedeDevolver(Proceso $proceso, User $usuario): bool
    {
        return $usuario->activo
            && $usuario->tienePermiso('documentos.registrar_legalizado')
            && $this->procesos->estaAsignado($proceso, $usuario)
            && $this->ofertas->puedeDevolver($proceso)
            && $this->procesos->transicionesDisponibles($proceso, $usuario)->contains('codigo', 'devolver_expediente');
    }

    /**
     * @param  array{0: string, 1: string}  $memorando  [ruta temporal, nombre original]
     * @param  array{0: string, 1: string}  $informe  [ruta temporal, nombre original]
     */
    public function devolver(Proceso $proceso, User $usuario, array $memorando, array $informe): void
    {
        $temporales = [$memorando[0] ?? '', $informe[0] ?? ''];

        try {
            if (! $this->puedeDevolver($proceso, $usuario)) {
                throw new DomainException('No puede devolver el expediente en este momento.');
            }
            if (empty($memorando[0])) {
                throw new DomainException('Cargue el Memorando de devolución del expediente (PDF).');
            }
            if (empty($informe[0])) {
                throw new DomainException('Cargue el Informe de devolución por falta de ofertas (PDF).');
            }

            $tipoMemo = $this->tipo(self::TIPO_MEMORANDO);
            $tipoInforme = $this->tipo(self::TIPO_INFORME);

            DB::transaction(function () use ($proceso, $usuario, $memorando, $informe, $tipoMemo, $tipoInforme) {
                $docMemo = $this->documentos->registrarLegalizadoDirecto(
                    $proceso, $tipoMemo, null, $memorando[0], $memorando[1] ?? basename($memorando[0]), $usuario, 'registrar_legalizado'
                );
                $this->documentos->registrarLegalizadoDirecto(
                    $proceso, $tipoInforme, null, $informe[0], $informe[1] ?? basename($informe[0]), $usuario, 'registrar_legalizado'
                );

                $motivo = 'Devolución del expediente: no se recibieron ofertas en las publicaciones solicitadas.';
                DB::table('devoluciones')->insert([
                    'proceso_id' => $proceso->id,
                    'documento_id' => $docMemo->id,
                    'motivo' => $motivo,
                    'reapertura_permitida' => true,
                    'user_id' => $usuario->id,
                ]);

                $this->aplicar($proceso, $usuario, 'devolver_expediente', $motivo);
                $proceso->refresh();
                // Cierra el ciclo y abre uno nuevo; el proceso queda en "Para Re-publicar".
                $this->aplicar($proceso, $usuario, 'reabrir', 'Nuevo ciclo: la unidad requirente remite nuevamente la solicitud de publicación.');

                DB::table('solicitudes_publicacion')
                    ->where('proceso_id', $proceso->id)->where('estado', 'remitida')
                    ->update([
                        'estado' => 'devuelta',
                        'devuelta_por' => $usuario->id,
                        'fecha_devolucion' => now(),
                        'motivo_devolucion' => $motivo,
                        'updated_at' => now(),
                    ]);

                DB::table('auditoria')->insert([
                    'user_id' => $usuario->id,
                    'evento' => 'proceso.devuelto_sin_ofertas',
                    'entidad' => 'procesos',
                    'entidad_id' => $proceso->id,
                    'valores_nuevos' => json_encode(['documento_memorando_id' => $docMemo->id]),
                ]);
            });
        } catch (QueryException $e) {
            $this->limpiar($temporales);
            throw new DomainException($this->mensajeDeBase($e), 0, $e);
        } catch (Throwable $e) {
            $this->limpiar($temporales);
            throw $e;
        }
    }

    private function aplicar(Proceso $proceso, User $usuario, string $codigo, string $comentario): void
    {
        DB::select('select aplicar_transicion_proceso(?, ?, ?, ?)', [$proceso->id, $codigo, $usuario->id, $comentario]);
    }

    private function tipo(string $codigo): TipoDocumento
    {
        return TipoDocumento::where('codigo', $codigo)->where('activo', true)->first()
            ?? throw new DomainException("El tipo de documento {$codigo} no está disponible.");
    }

    private function limpiar(array $temporales): void
    {
        Storage::disk(ServicioDocumento::DISCO)->delete(array_filter($temporales));
        $this->documentos->descartarRutasNuevas();
    }

    private function mensajeDeBase(QueryException $e): string
    {
        $mensaje = ($e->getPrevious() ?? $e)->getMessage();

        return preg_match('/ERROR:\s+(.+?)(?:\R|$)/s', $mensaje, $m) ? trim($m[1]) : 'No se pudo completar la acción.';
    }
}
