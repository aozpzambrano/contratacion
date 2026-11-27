<?php

namespace App\Services;

use App\Models\Documento;
use App\Models\Proceso;
use App\Models\TipoDocumento;
use App\Models\TransicionProceso;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * "Enviar a revision" de la unidad requirente. Los documentos se cargan en el mismo formulario del boton:
 * - Inicio: Informe de necesidad y Especificaciones tecnicas, los dos obligatorios en el primer envio
 *   (si ya hay uno cargado de antes, solo se reemplaza cuando se adjunta otro archivo).
 * - En correccion ("Reenviar"): una version nueva de cada documento con observaciones.
 * Los documentos y la transicion se registran en una sola transaccion.
 */
class ServicioEnvioRevision
{
    public const TRANSICIONES = ['enviar_a_revision', 'reenviar_a_revision'];

    /** Tipos del primer envio, en este orden. */
    public const TIPOS_PRIMER_ENVIO = ['INFORME_NECESIDAD', 'ESPECIFICACIONES_TECNICAS'];

    private const ESTADOS_OBSERVADOS = ['CON_OBSERVACIONES', 'EN_CORRECCION'];

    public function __construct(
        private readonly ServicioProceso $procesos,
        private readonly ServicioDocumento $documentos,
    ) {}

    public function puedeEnviar(Proceso $proceso, User $usuario): bool
    {
        return $this->transicion($proceso, $usuario) !== null;
    }

    private function transicion(Proceso $proceso, User $usuario): ?TransicionProceso
    {
        return $this->procesos->transicionesDisponibles($proceso, $usuario)
            ->first(fn (TransicionProceso $t) => in_array($t->codigo, self::TRANSICIONES, true));
    }

    /**
     * Archivos que pide el formulario, en orden.
     *
     * @return array<int, array{clave: string, etiqueta: string, tipo: TipoDocumento, documento: ?Documento, obligatorio: bool}>
     */
    public function campos(Proceso $proceso): array
    {
        $vigentes = fn () => Documento::query()
            ->where('proceso_id', $proceso->id)
            ->whereHas('estadoDocumento', fn ($q) => $q->where('grupo', '<>', 'anulado'))
            ->with(['tipoDocumento', 'estadoDocumento'])
            ->orderBy('id');

        if ($proceso->estadoProceso?->codigo === 'EN_CORRECCION') {
            return $vigentes()
                ->whereHas('estadoDocumento', fn ($q) => $q->whereIn('codigo', self::ESTADOS_OBSERVADOS))
                ->whereHas('tipoDocumento', fn ($q) => $q->where('requiere_revision', true))
                ->get()
                ->map(fn (Documento $d) => [
                    'clave' => 'doc_'.$d->id,
                    'etiqueta' => "Nueva versión de {$d->tipoDocumento->nombre}",
                    'tipo' => $d->tipoDocumento,
                    'documento' => $d,
                    'obligatorio' => true,
                ])->all();
        }

        $existentes = $vigentes()->whereHas('tipoDocumento', fn ($q) => $q->whereIn('codigo', self::TIPOS_PRIMER_ENVIO))->get()
            ->keyBy(fn (Documento $d) => $d->tipoDocumento->codigo);

        return collect(self::TIPOS_PRIMER_ENVIO)->map(function (string $codigo) use ($existentes) {
            $tipo = TipoDocumento::where('codigo', $codigo)->where('activo', true)->firstOrFail();
            $documento = $existentes->get($codigo);

            return [
                'clave' => strtolower($codigo),
                'etiqueta' => $tipo->nombre,
                'tipo' => $tipo,
                'documento' => $documento,
                'obligatorio' => $documento === null,
            ];
        })->all();
    }

    /**
     * @param  array<string, array{0: ?string, 1: ?string}>  $archivos  clave => [ruta temporal, nombre original]
     */
    public function enviar(Proceso $proceso, User $usuario, array $archivos): void
    {
        $temporales = collect($archivos)->map(fn ($a) => $a[0] ?? '')->filter()->values()->all();

        try {
            $transicion = $this->transicion($proceso, $usuario)
                ?? throw new DomainException('No puede enviar el proceso a revisión en este momento.');

            $campos = $this->campos($proceso);
            foreach ($campos as $campo) {
                if ($campo['obligatorio'] && empty($archivos[$campo['clave']][0])) {
                    throw new DomainException("Cargue {$campo['etiqueta']}.");
                }
            }

            DB::transaction(function () use ($proceso, $usuario, $transicion, $campos, $archivos) {
                foreach ($campos as $campo) {
                    [$ruta, $nombre] = [$archivos[$campo['clave']][0] ?? null, $archivos[$campo['clave']][1] ?? null];
                    if (empty($ruta)) {
                        continue;
                    }
                    $nombre = $nombre ?: basename($ruta);

                    if ($campo['documento'] !== null) {
                        $this->documentos->agregarVersion($campo['documento'], $ruta, $nombre, null, $usuario);
                    } else {
                        $this->documentos->crearDocumento($proceso->fresh(), $campo['tipo'], null, $ruta, $nombre, null, $usuario);
                    }
                }

                $this->procesos->aplicarTransicion($proceso->fresh(), $transicion, $usuario);
            });
        } catch (Throwable $e) {
            Storage::disk(ServicioDocumento::DISCO)->delete(array_filter($temporales));
            $this->documentos->descartarRutasNuevas();
            throw $e;
        }
    }
}
