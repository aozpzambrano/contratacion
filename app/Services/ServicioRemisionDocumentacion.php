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
 * Tras "Remitir ofertas" el proceso vuelve a la unidad requirente (Para documentacion): carga el Estudio de Mercado
 * y la Certificacion POA (un PDF legalizado cada uno) y los remite a Compras (Para comision).
 */
class ServicioRemisionDocumentacion
{
    public const TIPO_ESTUDIO = 'ESTUDIO_MERCADO';

    public const TIPO_POA = 'CERTIFICACION_POA';

    public const TRANSICION = 'remitir_documentacion';

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
     * @param  array{0: string, 1: string}  $estudio  [ruta temporal, nombre original]
     * @param  array{0: string, 1: string}  $poa  [ruta temporal, nombre original]
     */
    public function remitir(Proceso $proceso, User $usuario, array $estudio, array $poa): void
    {
        $temporales = [$estudio[0] ?? '', $poa[0] ?? ''];

        try {
            $transicion = $this->transicion($proceso, $usuario)
                ?? throw new DomainException('No puede remitir la documentación en este momento.');

            if (empty($estudio[0])) {
                throw new DomainException('Cargue el Estudio de Mercado (PDF legalizado).');
            }
            if (empty($poa[0])) {
                throw new DomainException('Cargue la Certificación POA (PDF legalizado).');
            }

            $tipoEstudio = $this->tipo(self::TIPO_ESTUDIO);
            $tipoPoa = $this->tipo(self::TIPO_POA);

            DB::transaction(function () use ($proceso, $usuario, $transicion, $estudio, $poa, $tipoEstudio, $tipoPoa) {
                $this->documentos->registrarLegalizadoDirecto($proceso, $tipoEstudio, null, $estudio[0], $estudio[1] ?? basename($estudio[0]), $usuario, 'legalizar');
                $this->documentos->registrarLegalizadoDirecto($proceso, $tipoPoa, null, $poa[0], $poa[1] ?? basename($poa[0]), $usuario, 'legalizar');

                DB::table('auditoria')->insert([
                    'user_id' => $usuario->id,
                    'evento' => 'proceso.documentacion_remitida',
                    'entidad' => 'procesos',
                    'entidad_id' => $proceso->id,
                    'valores_nuevos' => json_encode(['documentos' => [self::TIPO_ESTUDIO, self::TIPO_POA]]),
                ]);

                // La BD comprueba que ambos documentos esten legalizados.
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
