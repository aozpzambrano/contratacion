<?php

namespace App\Services;

use App\Models\Documento;
use App\Models\Proceso;
use App\Models\TipoDocumento;
use App\Models\TransicionProceso;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * "Remitir documentos legalizados": la unidad requirente entrega los PDF firmados electronicamente,
 * el memorando de solicitud de publicacion y los datos de la solicitud (numero de memorando,
 * numero de publicaciones y horas de cada una). Todo ocurre en una sola transaccion.
 */
class ServicioLegalizacion
{
    public const MAX_PUBLICACIONES = 5;

    public const MAX_HORAS = 8760;

    public const TRANSICIONES = ['remitir_legalizados', 'remitir_republicacion', self::REABRIR];

    /** La unidad reabre un proceso en "Para reapertura": nuevo ciclo con el mismo flujo del ciclo 1. */
    public const REABRIR = 'reabrir_unidad';

    public const TIPO_DISTRIBUTIVO = 'DISTRIBUTIVO';

    public const TIPO_MEMORANDO = 'MEMORANDO_SOLICITUD_PUBLICACION';

    public function __construct(
        private readonly ServicioProceso $procesos,
        private readonly ServicioDocumento $documentos,
    ) {}

    /** El usuario puede ver y ejecutar "Remitir documentos legalizados" ahora. */
    public function puedeRemitir(Proceso $proceso, User $usuario): bool
    {
        return $this->transicionRemitir($proceso, $usuario) !== null;
    }

    private function transicionRemitir(Proceso $proceso, User $usuario): ?TransicionProceso
    {
        // "Remitir" (primera vez) y "Remitir" tras una devolucion sin ofertas (Para Re-publicar) son lo mismo.
        return $this->procesos->transicionesDisponibles($proceso, $usuario)
            ->first(fn (TransicionProceso $t) => in_array($t->codigo, self::TRANSICIONES, true));
    }

    /**
     * Tipos de documento que la unidad debe cargar junto con el memorando y que aun no tiene en el proceso.
     * Salen de los "Documentos obligatorios" de la transicion (editables por el administrador).
     */
    public function tiposAdicionalesPendientes(Proceso $proceso): Collection
    {
        // al reabrir (Para reapertura) los requisitos son los de "Remitir" desde Para Re-publicar
        $estadoOrigen = $proceso->estadoProceso?->codigo === 'PARA_REAPERTURA'
            ? DB::table('estados_proceso')->where('codigo', 'PARA_REPUBLICAR')->value('id')
            : $proceso->estado_proceso_id;

        $requeridos = DB::table('transicion_proceso_requisitos as rq')
            ->join('transiciones_proceso as t', 't.id', '=', 'rq.transicion_id')
            ->where('t.estado_origen_id', $estadoOrigen)
            ->whereIn('t.codigo', self::TRANSICIONES)
            ->pluck('rq.tipo_documento_id');

        $existentes = Documento::query()
            ->where('proceso_id', $proceso->id)
            ->whereIn('tipo_documento_id', $requeridos)
            ->whereHas('estadoDocumento', fn ($q) => $q->where('codigo', '!=', 'ANULADO'))
            ->pluck('tipo_documento_id');

        return TipoDocumento::query()->whereIn('id', $requeridos)->whereNotIn('id', $existentes)->where('activo', true)->orderBy('id')->get()
            ->sortBy(fn (TipoDocumento $t) => ServicioExpedientePublicacion::posicionTipo($t->codigo), SORT_REGULAR)->values();
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $legalizados  por id de documento: [ruta temporal, nombre original]
     * @param  array{0: string, 1: string}  $memorando  [ruta temporal, nombre original]
     * @param  array<int, int|string>  $horas  horas de cada publicacion, en orden (la cantidad = numero de publicaciones)
     * @param  array<int, array<int, array{0: string, 1: string}>>  $adicionales  por id de tipo de documento: lista de [ruta temporal, nombre original]
     * @param  array<int, array{0: string, 1: string}>  $distributivos  uno o varios archivos (PDF, Word o Excel) del tipo Distributivo; obligatorio si el proceso aun no tiene uno
     */
    public function remitir(Proceso $proceso, User $usuario, array $legalizados, array $memorando, string $numeroMemorando, array $horas, array $adicionales = [], array $distributivos = []): void
    {
        $temporales = array_merge(
            array_column($legalizados, 0),
            [$memorando[0] ?? ''],
            array_column($distributivos, 0),
            ...array_map(fn ($lista) => array_column($lista, 0), array_values($adicionales)),
        );

        try {
            $transicion = $this->transicionRemitir($proceso, $usuario)
                ?? throw new DomainException('No puede remitir documentos legalizados en este momento.');

            $numeroMemorando = trim($numeroMemorando);
            $horas = $this->validarDatos($numeroMemorando, $horas);

            $pendientes = $this->documentos->documentosPorLegalizar($proceso);
            foreach ($pendientes as $documento) {
                if (! isset($legalizados[$documento->id])) {
                    throw new DomainException("Cargue el PDF legalizado de \"{$documento->titulo}\".");
                }
            }
            if (empty($memorando[0])) {
                throw new DomainException('Cargue el memorando de solicitud de publicación (PDF).');
            }

            $tipoMemorando = TipoDocumento::where('codigo', self::TIPO_MEMORANDO)->where('activo', true)->first()
                ?? throw new DomainException('El tipo de documento "Memorando de solicitud de publicación" no está disponible.');

            DB::transaction(function () use ($proceso, $usuario, $transicion, $pendientes, $legalizados, $memorando, $tipoMemorando, $numeroMemorando, $horas, $adicionales, $distributivos) {
                // Reapertura: los datos del ciclo anterior quedan en el historial y se abre el ciclo nuevo
                if ($transicion->codigo === self::REABRIR) {
                    $transicion = $this->abrirNuevoCiclo($proceso, $usuario, $transicion);
                }

                foreach ($pendientes as $documento) {
                    [$ruta, $nombre] = $legalizados[$documento->id];
                    $this->documentos->legalizarDocumento($documento, $ruta, $nombre, $usuario);
                }

                $docMemorando = $this->documentos->guardarInterno(
                    $proceso, $tipoMemorando, "Memorando N.° {$numeroMemorando}", $memorando[0], $memorando[1] ?? basename($memorando[0]), null, $usuario
                );
                DB::select('select aplicar_transicion_documento(?, ?, ?, ?)', [$docMemorando->id, 'registrar_sin_revision', $usuario->id, null]);

                // Fichas tecnicas, certificados, etc.: cada archivo es un documento del tipo; si falta alguno
                // requerido, la regla de la base lo informa por nombre ("Faltan documentos requeridos: ...").
                foreach ($adicionales as $tipoId => $lista) {
                    $tipo = TipoDocumento::findOrFail($tipoId);
                    foreach ($lista as [$ruta, $nombre]) {
                        $doc = $this->documentos->guardarInterno($proceso, $tipo, null, $ruta, $nombre ?: basename($ruta), null, $usuario);
                        DB::select('select aplicar_transicion_documento(?, ?, ?, ?)', [$doc->id, 'registrar_sin_revision', $usuario->id, null]);
                    }
                }

                // Distributivo: uno o varios archivos PDF, Word o Excel (sin legalizacion)
                if ($distributivos !== []) {
                    $tipoDistributivo = TipoDocumento::where('codigo', self::TIPO_DISTRIBUTIVO)->where('activo', true)->first()
                        ?? throw new DomainException('El tipo de documento "Distributivo" no está disponible.');
                    foreach ($distributivos as [$ruta, $nombre]) {
                        $doc = $this->documentos->guardarInterno($proceso, $tipoDistributivo, null, $ruta, $nombre ?: basename($ruta), null, $usuario);
                        DB::select('select aplicar_transicion_documento(?, ?, ?, ?)', [$doc->id, 'registrar_sin_revision', $usuario->id, null]);
                    }
                }

                $solicitudId = DB::table('solicitudes_publicacion')->insertGetId([
                    'proceso_id' => $proceso->id,
                    'ciclo' => $proceso->ciclo,
                    'numero_memorando' => $numeroMemorando,
                    'memorando_documento_id' => $docMemorando->id,
                    'remitida_por' => $usuario->id,
                ]);
                foreach (array_values($horas) as $i => $h) {
                    DB::table('solicitud_publicacion_detalle')->insert([
                        'solicitud_id' => $solicitudId,
                        'numero_publicacion' => $i + 1,
                        'horas' => $h,
                    ]);
                }

                DB::table('auditoria')->insert([
                    'user_id' => $usuario->id,
                    'evento' => 'proceso.legalizados_remitidos',
                    'entidad' => 'procesos',
                    'entidad_id' => $proceso->id,
                    'valores_nuevos' => json_encode([
                        'solicitud_id' => $solicitudId,
                        'numero_memorando' => $numeroMemorando,
                        'horas' => array_values($horas),
                    ]),
                ]);

                // Las reglas de la BD comprueban que todo quedo legalizado y que existe la solicitud.
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

    /**
     * Reabre el proceso: la solicitud de publicacion del ciclo anterior queda "devuelta" (historial), el proceso pasa a
     * Para Re-publicar con el ciclo siguiente y se devuelve la transicion "Remitir" que continua el mismo flujo.
     */
    private function abrirNuevoCiclo(Proceso $proceso, User $usuario, TransicionProceso $reabrir): TransicionProceso
    {
        $motivo = 'Reapertura del proceso por la unidad requirente: nuevo ciclo.';
        DB::table('solicitudes_publicacion')
            ->where('proceso_id', $proceso->id)->where('estado', 'remitida')
            ->update([
                'estado' => 'devuelta',
                'devuelta_por' => $usuario->id,
                'fecha_devolucion' => now(),
                'motivo_devolucion' => $motivo,
                'updated_at' => now(),
            ]);

        $this->procesos->aplicarTransicion($proceso, $reabrir, $usuario, $motivo);
        $proceso->refresh()->unsetRelation('estadoProceso');
        $proceso->unsetRelation('estadoMacro');

        return $this->transicionRemitir($proceso, $usuario)
            ?? throw new DomainException('No se pudo continuar con la remisión después de reabrir el proceso.');
    }

    /** @return array<int, int> horas validadas */
    private function validarDatos(string $numeroMemorando, array $horas): array
    {
        if ($numeroMemorando === '' || mb_strlen($numeroMemorando) > 60) {
            throw new DomainException('Ingrese el número del memorando (máximo 60 caracteres).');
        }

        $horas = array_values($horas);
        if ($horas === [] || count($horas) > self::MAX_PUBLICACIONES) {
            throw new DomainException('El número de publicaciones debe estar entre 1 y '.self::MAX_PUBLICACIONES.'.');
        }

        foreach ($horas as $i => $h) {
            if (filter_var($h, FILTER_VALIDATE_INT) === false || (int) $h < 24 || (int) $h > self::MAX_HORAS || (int) $h % 24 !== 0) {
                throw new DomainException('Las horas de la publicación '.($i + 1).' deben ser 24 o un múltiplo de 24 (un día completo), hasta '.self::MAX_HORAS.'.');
            }
            $horas[$i] = (int) $h;
        }

        return $horas;
    }

    /** Borra los temporales y los archivos definitivos de una remision que no se completo. */
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
