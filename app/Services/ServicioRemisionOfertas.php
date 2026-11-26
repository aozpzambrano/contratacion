<?php

namespace App\Services;

use App\Models\Proceso;
use App\Models\Rol;
use App\Models\TipoDocumento;
use App\Models\TransicionProceso;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Remitir ofertas" (Publicado -> Para documentacion): el analista asignado sube la Necesidad de Contratacion
 * Finalizada, el Memorando de solicitud de la designacion de la comision y el Memorando de designacion de la
 * comision (un PDF legalizado cada uno) y remite las ofertas a la unidad requirente. La BD exige los tres
 * documentos legalizados y que la decision del flujo sea "remitir".
 *
 * Tambien calcula que designacion corresponde (Servidor Afin, Comision Tecnica o Comision + Financiero + Juridico)
 * segun el mayor monto total entre las ofertas recibidas, para mostrarla como aviso informativo a Compras.
 */
class ServicioRemisionOfertas
{
    public const TIPO_NECESIDAD = 'NECESIDAD_CONTRATACION_FINALIZADA';

    public const TIPO_MEMO_SOLICITUD = 'MEMORANDO_SOLICITUD_DESIGNACION_COMISION';

    public const TIPO_MEMO_DESIGNACION = 'MEMORANDO_DESIGNACION_COMISION';

    /** Texto informativo por tipo de designacion (codigo de tipos_designacion). */
    public const MENSAJES_DESIGNACION = [
        'DELEGADO' => 'Servidor Afín',
        'COMISION' => 'Comisión Técnica',
        'COMISION_FINANCIERO_JURIDICO' => 'Comisión Técnica + Financiero + Jurídico',
    ];

    public const TRANSICION = 'remitir_ofertas';

    /** Dominio de los usuarios que se crean para la Comision Tecnica de cada proceso. */
    public const DOMINIO_COMISION = '@ailep.com';

    public function __construct(
        private readonly ServicioProceso $procesos,
        private readonly ServicioDocumento $documentos,
        private readonly ServicioOferta $ofertas,
    ) {}

    public function puedeRemitir(Proceso $proceso, User $usuario): bool
    {
        return $this->transicion($proceso, $usuario) !== null
            && $usuario->tienePermiso('documentos.registrar_legalizado')
            && $this->procesos->estaAsignado($proceso, $usuario)
            && $this->ofertas->puedeRemitir($proceso);
    }

    private function transicion(Proceso $proceso, User $usuario): ?TransicionProceso
    {
        return $this->procesos->transicionesDisponibles($proceso, $usuario)->firstWhere('codigo', self::TRANSICION);
    }

    /**
     * Designacion que corresponde segun el mayor monto total entre las ofertas recibidas del ciclo actual.
     *
     * @return array{monto: float, codigo: string, nombre: string}|null null si aun no hay ofertas
     */
    public function designacionSugerida(Proceso $proceso): ?array
    {
        $monto = DB::table('v_ofertas_totales as t')
            ->join('publicaciones as pu', 'pu.id', '=', 't.publicacion_id')
            ->where('t.proceso_id', $proceso->id)
            ->where('pu.ciclo', $proceso->ciclo)
            ->max('t.total');

        if ($monto === null) {
            return null;
        }

        $tipo = DB::selectOne('SELECT td.codigo FROM tipos_designacion td WHERE td.id = tipo_designacion_sugerido(?)', [$monto]);
        if (! $tipo || ! isset(self::MENSAJES_DESIGNACION[$tipo->codigo])) {
            return null;
        }

        return ['monto' => (float) $monto, 'codigo' => $tipo->codigo, 'nombre' => self::MENSAJES_DESIGNACION[$tipo->codigo]];
    }

    /** El aviso se muestra al Jefe/Analista de Compras con las ofertas registradas y antes de remitirlas. */
    public function puedeVerAviso(Proceso $proceso, User $usuario): bool
    {
        return $proceso->estadoProceso?->codigo === 'PUBLICADO'
            && ($usuario->tieneRol('COMPRAS_JEFE') || $usuario->tieneRol('COMPRAS_ANALISTA'))
            && $this->ofertas->ofertasRegistradas($proceso);
    }

    /**
     * @param  array{0: string, 1: string}  $necesidad  [ruta temporal, nombre original]
     * @param  array{0: string, 1: string}  $memoSolicitud  [ruta temporal, nombre original]
     * @param  array{0: string, 1: string}  $memoDesignacion  [ruta temporal, nombre original]
     * @return array{usuario: string, nombre: string, clave: string} credenciales de la Comision Tecnica (la clave se muestra una sola vez)
     */
    public function remitir(Proceso $proceso, User $usuario, array $necesidad, array $memoSolicitud, array $memoDesignacion): array
    {
        $temporales = [$necesidad[0] ?? '', $memoSolicitud[0] ?? '', $memoDesignacion[0] ?? ''];

        try {
            if (! $this->puedeRemitir($proceso, $usuario)) {
                throw new DomainException('No puede remitir las ofertas en este momento.');
            }
            if (empty($necesidad[0])) {
                throw new DomainException('Cargue la Necesidad de Contratación Finalizada (PDF).');
            }
            if (empty($memoSolicitud[0])) {
                throw new DomainException('Cargue el Memorando de Solicitud de la Designación de la Comisión (PDF).');
            }
            if (empty($memoDesignacion[0])) {
                throw new DomainException('Cargue el Memorando de Designación de la Comisión (PDF).');
            }

            $transicion = $this->transicion($proceso, $usuario);
            $cargas = [
                [$this->tipo(self::TIPO_NECESIDAD), $necesidad],
                [$this->tipo(self::TIPO_MEMO_SOLICITUD), $memoSolicitud],
                [$this->tipo(self::TIPO_MEMO_DESIGNACION), $memoDesignacion],
            ];

            $credenciales = null;
            DB::transaction(function () use ($proceso, $usuario, $transicion, $cargas, &$credenciales) {
                foreach ($cargas as [$tipo, $archivo]) {
                    $this->documentos->registrarLegalizadoDirecto($proceso, $tipo, null, $archivo[0], $archivo[1] ?? basename($archivo[0]), $usuario, 'registrar_legalizado');
                }

                DB::table('auditoria')->insert([
                    'user_id' => $usuario->id,
                    'evento' => 'proceso.ofertas_remitidas',
                    'entidad' => 'procesos',
                    'entidad_id' => $proceso->id,
                    'valores_nuevos' => json_encode(['documentos' => [self::TIPO_NECESIDAD, self::TIPO_MEMO_SOLICITUD, self::TIPO_MEMO_DESIGNACION]]),
                ]);

                // La BD comprueba la decision del flujo y que los tres documentos esten legalizados.
                $this->procesos->aplicarTransicion($proceso, $transicion, $usuario);

                $credenciales = $this->crearUsuarioComision($proceso, $usuario);
            });

            return $credenciales;
        } catch (QueryException $e) {
            $this->limpiar($temporales);
            throw new DomainException($this->mensajeDeBase($e), 0, $e);
        } catch (Throwable $e) {
            $this->limpiar($temporales);
            throw $e;
        }
    }

    /**
     * Crea (o reactiva, si el proceso vuelve a remitirse tras una devolucion) el usuario de la Comision Tecnica
     * del proceso, con clave temporal, y lo asigna al proceso. La clave solo se devuelve aqui.
     */
    private function crearUsuarioComision(Proceso $proceso, User $analista): array
    {
        $referencia = $proceso->codigo ?: 'proceso-'.$proceso->id;
        $correo = $this->correoComision($proceso, $referencia);
        $clave = Str::before($correo, '@'); // la clave temporal es el mismo usuario (p. ej. comision.001)
        $nombre = 'Comisión Técnica '.$referencia;

        $comision = User::where('email', $correo)->first() ?? new User(['email' => $correo]);
        $comision->fill(['name' => $nombre, 'activo' => true, 'debe_cambiar_clave' => true]);
        $comision->password = $clave; // el modelo lo cifra
        $comision->save();

        $rol = Rol::where('codigo', 'COMISION_TECNICA')->firstOrFail();
        DB::table('usuario_rol')->updateOrInsert(['user_id' => $comision->id, 'rol_id' => $rol->id], []);
        $comision->olvidarPermisos();

        DB::table('asignaciones_proceso')->where('proceso_id', $proceso->id)->where('user_id', $comision->id)->where('activo', true)
            ->update(['activo' => false, 'fecha_fin' => now(), 'updated_at' => now()]);
        DB::table('asignaciones_proceso')->insert([
            'proceso_id' => $proceso->id,
            'user_id' => $comision->id,
            'rol_id' => $rol->id,
            'fase_id' => DB::table('fases')->where('codigo', 'PRECONTRACTUAL')->value('id'),
            'etapa_id' => DB::table('etapas')->where('codigo', 'EVALUACION')->value('id'),
            'asignado_por' => $analista->id,
        ]);

        DB::table('auditoria')->insert([
            'user_id' => $analista->id,
            'evento' => 'proceso.usuario_comision_creado',
            'entidad' => 'procesos',
            'entidad_id' => $proceso->id,
            'valores_nuevos' => json_encode(['usuario' => $correo]),
        ]);

        return ['usuario' => $correo, 'nombre' => $nombre, 'clave' => $clave];
    }

    /**
     * comision.<anio>.<numero del proceso>@ailep.com (p. ej. PE-AILEP-2026-001 -> comision.2026.001@ailep.com). Si ese correo ya es de
     * la comision de otro proceso (mismo anio y numero con otro tipo de proceso) se usa el codigo completo.
     */
    private function correoComision(Proceso $proceso, string $referencia): string
    {
        $partes = explode('-', $referencia);
        $numero = Str::slug((string) end($partes));
        $anio = count($partes) >= 2 ? Str::slug($partes[count($partes) - 2]) : '';

        $candidatos = $anio !== '' ? ['comision.'.$anio.'.'.$numero] : [];
        $candidatos[] = 'comision.'.Str::slug($referencia);

        foreach ($candidatos as $local) {
            $correo = $local.self::DOMINIO_COMISION;
            $existente = User::where('email', $correo)->first();
            $deOtroProceso = $existente && DB::table('asignaciones_proceso')->where('user_id', $existente->id)->where('proceso_id', '<>', $proceso->id)->exists();
            if (! $deOtroProceso) {
                return $correo;
            }
        }

        return 'comision.'.Str::slug($referencia).'.'.$proceso->id.self::DOMINIO_COMISION;
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
