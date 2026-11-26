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
 * "Remitir Informe": el usuario de la Comision Tecnica del proceso elige el tipo de informe y carga un PDF por
 * cada documento. La BD exige los documentos legalizados segun la transicion:
 *  - Devolucion de expediente (-> Para reapertura): memorando de remision + informe de devolucion.
 *  - Seleccion de proveedor (-> Aprobado): memorando de remision + informe + acta de recepcion de ofertas + validacion de firmas.
 * Al terminar, el usuario de la comision sigue activo y asignado solo para consultar (ver ServicioDocumento).
 */
class ServicioInformeComision
{
    public const DEVOLUCION = 'devolucion';

    public const SELECCION = 'seleccion';

    /** Documentos de cada informe: campo del formulario => [codigo de tipo, etiqueta]. */
    public const DOCUMENTOS = [
        self::DEVOLUCION => [
            'memorando' => ['MEMORANDO_REMISION_INFORME', 'Memorando de remisión de informe'],
            'informe' => ['INFORME_RECOMENDACION_DEVOLUCION', 'Informe de Recomendación de Devolución de Expediente'],
        ],
        self::SELECCION => [
            'memorando' => ['MEMORANDO_REMISION_INFORME', 'Memorando de remisión de informe'],
            'informe' => ['INFORME_SELECCION_PROVEEDOR', 'Informe de Recomendación de Selección de Proveedor'],
            'acta' => ['ACTA_RECEPCION_OFERTAS', 'Acta de Constancia de Recepción de Ofertas'],
            'firmas' => ['VALIDACION_FIRMAS_INFORME', 'Validación de firmas'],
        ],
    ];

    public const TRANSICIONES = [
        self::DEVOLUCION => 'remitir_informe_devolucion',
        self::SELECCION => 'remitir_informe_seleccion',
    ];

    public function __construct(
        private readonly ServicioProceso $procesos,
        private readonly ServicioDocumento $documentos,
    ) {}

    public function puedeRemitir(Proceso $proceso, User $usuario): bool
    {
        return $usuario->activo
            && $proceso->estadoProceso?->codigo === 'EN_COMISION'
            && $usuario->tienePermiso('procesos.remitir_informe')
            && $usuario->tienePermiso('documentos.registrar_legalizado')
            && $this->procesos->estaAsignado($proceso, $usuario);
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $archivos  campo => [ruta temporal, nombre original]
     */
    public function remitir(Proceso $proceso, User $usuario, string $tipoInforme, array $archivos): void
    {
        $temporales = collect($archivos)->map(fn ($a) => $a[0] ?? '')->filter()->values()->all();

        try {
            if (! $this->puedeRemitir($proceso, $usuario)) {
                throw new DomainException('No puede remitir el informe en este momento.');
            }
            $definicion = self::DOCUMENTOS[$tipoInforme] ?? throw new DomainException('Seleccione el tipo de informe.');
            foreach ($definicion as $campo => [$codigo, $etiqueta]) {
                if (empty($archivos[$campo][0])) {
                    throw new DomainException("Cargue el documento: {$etiqueta} (PDF).");
                }
            }
            $tipos = [];
            foreach ($definicion as $campo => [$codigo]) {
                $tipos[$campo] = TipoDocumento::where('codigo', $codigo)->where('activo', true)->first()
                    ?? throw new DomainException("El tipo de documento {$codigo} no está disponible.");
            }

            DB::transaction(function () use ($proceso, $usuario, $tipoInforme, $definicion, $archivos, $tipos) {
                $registrados = [];
                foreach ($definicion as $campo => [$codigo]) {
                    $registrados[$campo] = $this->documentos->registrarLegalizadoDirecto(
                        $proceso, $tipos[$campo], null, $archivos[$campo][0], $archivos[$campo][1] ?? basename($archivos[$campo][0]), $usuario, 'registrar_legalizado'
                    );
                }

                $motivo = null;
                if ($tipoInforme === self::DEVOLUCION) {
                    $motivo = 'La Comisión Técnica recomienda la devolución del expediente.';
                    DB::table('devoluciones')->insert([
                        'proceso_id' => $proceso->id,
                        'documento_id' => $registrados['informe']->id,
                        'motivo' => $motivo,
                        'reapertura_permitida' => true,
                        'user_id' => $usuario->id,
                    ]);
                }

                DB::table('auditoria')->insert([
                    'user_id' => $usuario->id,
                    'evento' => 'proceso.informe_comision_remitido',
                    'entidad' => 'procesos',
                    'entidad_id' => $proceso->id,
                    'valores_nuevos' => json_encode(['tipo' => $tipoInforme, 'documentos' => array_column($definicion, 0)]),
                ]);

                DB::select('select aplicar_transicion_proceso(?, ?, ?, ?)', [$proceso->id, self::TRANSICIONES[$tipoInforme], $usuario->id, $motivo]);
            });
        } catch (QueryException $e) {
            $this->limpiar($temporales);
            throw new DomainException($this->mensajeDeBase($e), 0, $e);
        } catch (Throwable $e) {
            $this->limpiar($temporales);
            throw $e;
        }
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
