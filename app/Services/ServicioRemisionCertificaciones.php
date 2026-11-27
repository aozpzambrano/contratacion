<?php

namespace App\Services;

use App\Models\Proceso;
use App\Models\TipoDocumento;
use App\Models\TransicionProceso;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Tras el informe de seleccion de proveedor el proceso pasa a la unidad requirente (Para certificaciones): registra la
 * Certificacion POA, la Certificacion PAC, la Certificacion presupuestaria y la Autorizacion de gasto (un PDF legalizado
 * cada uno) y las remite (Aprobado). La BD exige los cuatro documentos legalizados.
 */
class ServicioRemisionCertificaciones
{
    public const TRANSICION = 'remitir_certificaciones';

    /** Campo del formulario => [codigo de tipo, etiqueta]. */
    public const DOCUMENTOS = [
        'poa' => ['CERTIFICACION_POA', 'Certificación POA'],
        'pac' => ['PAC', 'Certificación PAC'],
        'presupuestaria' => ['CERTIFICACION_PRESUPUESTARIA', 'Certificación Presupuestaria'],
        'gasto' => ['AUTORIZACION_GASTO', 'Autorización de Gasto'],
    ];

    public function __construct(
        private readonly ServicioProceso $procesos,
        private readonly ServicioDocumento $documentos,
    ) {}

    public function puedeRemitir(Proceso $proceso, User $usuario): bool
    {
        return $this->transicion($proceso, $usuario) !== null;
    }

    private function transicion(Proceso $proceso, User $usuario): ?TransicionProceso
    {
        return $this->procesos->transicionesDisponibles($proceso, $usuario)->firstWhere('codigo', self::TRANSICION);
    }

    /**
     * @param  array<string, array<int, array{0: string, 1: string}>|array{0: string, 1: string}>  $archivos
     *                                                                                                         campo => lista de [ruta temporal, nombre original] (tambien se admite un solo par)
     */
    public function remitir(Proceso $proceso, User $usuario, array $archivos): void
    {
        // cada documento admite varios PDF; un solo par [ruta, nombre] se toma como una lista de uno
        $archivos = array_map(fn ($a) => is_string($a[0] ?? null) ? [$a] : array_values(array_filter((array) $a, fn ($p) => ! empty($p[0]))), $archivos);
        $temporales = collect($archivos)->flatten(1)->map(fn ($a) => $a[0] ?? '')->filter()->values()->all();

        try {
            $transicion = $this->transicion($proceso, $usuario)
                ?? throw new DomainException('No puede remitir las certificaciones en este momento.');

            foreach (self::DOCUMENTOS as $campo => [, $etiqueta]) {
                if (empty($archivos[$campo])) {
                    throw new DomainException("Cargue {$etiqueta} (al menos un PDF legalizado).");
                }
            }
            $tipos = [];
            foreach (self::DOCUMENTOS as $campo => [$codigo]) {
                $tipos[$campo] = $this->tipo($codigo);
            }

            DB::transaction(function () use ($proceso, $usuario, $transicion, $archivos, $tipos) {
                foreach (self::DOCUMENTOS as $campo => [$codigo]) {
                    foreach ($archivos[$campo] as [$ruta, $nombre]) {
                        $this->documentos->registrarLegalizadoDirecto($proceso, $tipos[$campo], null, $ruta, $nombre ?: basename($ruta), $usuario, 'legalizar');
                    }
                }

                DB::table('auditoria')->insert([
                    'user_id' => $usuario->id,
                    'evento' => 'proceso.certificaciones_remitidas',
                    'entidad' => 'procesos',
                    'entidad_id' => $proceso->id,
                    'valores_nuevos' => json_encode(['documentos' => array_column(self::DOCUMENTOS, 0)]),
                ]);

                // La BD comprueba que los cuatro documentos esten legalizados.
                $this->procesos->aplicarTransicion($proceso, $transicion, $usuario);
            });
        } catch (QueryException $e) {
            $this->limpiar($temporales);
            throw new DomainException($this->mensajeDeBase($e), 0, $e);
        } catch (Throwable $e) {
            $this->limpiar($temporales);
            throw $e;
        }
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
