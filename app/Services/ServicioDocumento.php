<?php

namespace App\Services;

use App\Models\Archivo;
use App\Models\Documento;
use App\Models\DocumentoVersion;
use App\Models\EstadoDocumento;
use App\Models\Proceso;
use App\Models\TipoDocumento;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Carga, versionado y descarga de documentos del expediente.
 *
 * Los archivos viven en el disco privado "documentos". La interfaz los deja primero en
 * "temporal/" y este servicio los valida y los mueve a su ubicacion definitiva
 * (procesos/{proceso}/documentos/{documento}/{uuid}.{ext}); el nombre original solo se guarda en la BD.
 */
class ServicioDocumento
{
    public const DISCO = 'documentos';

    /** Roles que cargan documentos fuera de los estados editables del proceso, si estan asignados. */
    private const ROL_QUE_CARGA_SIEMPRE = 'COMPRAS_ANALISTA';

    /** Estado en que queda el proceso cuando Compras lo devuelve a la unidad despues de legalizado. */
    public const ESTADO_TRAS_DEVOLUCION = 'PARA_REVISION';

    // ---------------------------------------------------------------- permisos

    public function puedeVerProceso(Proceso $proceso, User $usuario): bool
    {
        return $usuario->activo && Proceso::visiblesPara($usuario)->whereKey($proceso->id)->exists();
    }

    /**
     * La unidad requirente carga mientras el proceso es editable (inicio / en correccion);
     * despues solo el analista asignado. Nunca en un proceso finalizado.
     */
    /** La Comision Tecnica del proceso solo modifica mientras esta en comision; despues queda en solo lectura. */
    private function comisionSoloLectura(Proceso $proceso, User $usuario): bool
    {
        return $usuario->tieneRol('COMISION_TECNICA') && $proceso->estadoProceso?->codigo !== 'EN_COMISION';
    }

    public function puedeSubir(Proceso $proceso, User $usuario): bool
    {
        if ($this->comisionSoloLectura($proceso, $usuario)) {
            return false;
        }
        if (! $usuario->activo || ! $usuario->tienePermiso('documentos.subir')
            || ! $this->puedeVerProceso($proceso, $usuario)) {
            return false;
        }
        if ($proceso->estadoProceso?->es_final) {
            return false;
        }
        // Devuelto por Compras tras la legalizacion: solo la unidad requirente completa documentos.
        if ($this->enRevisionTrasDevolucion($proceso)) {
            return $this->esUnidadDelProceso($usuario);
        }
        if ($proceso->esEditable()) {
            return true;
        }

        return $this->esAnalistaAsignado($proceso, $usuario);
    }

    /** El proceso fue devuelto a la unidad despues de legalizado ("Para revision"). */
    public function enRevisionTrasDevolucion(Proceso $proceso): bool
    {
        return $proceso->estadoProceso?->codigo === self::ESTADO_TRAS_DEVOLUCION;
    }

    private function esUnidadDelProceso(User $usuario): bool
    {
        return $usuario->tieneRol('UNIDAD_REQUIRENTE');
    }

    /**
     * Eliminar (anular) un documento: solo la unidad, con el proceso devuelto, y solo los tipos que el
     * administrador marco como editables tras la devolucion.
     */
    public function puedeAnular(Documento $documento, User $usuario): bool
    {
        $documento->loadMissing(['proceso.estadoProceso', 'estadoDocumento', 'tipoDocumento']);

        return $usuario->activo && $usuario->tienePermiso('documentos.anular')
            && $this->puedeVerProceso($documento->proceso, $usuario)
            && $this->enRevisionTrasDevolucion($documento->proceso)
            && $this->esUnidadDelProceso($usuario)
            && $documento->tipoDocumento->editable_tras_devolucion
            && $documento->estadoDocumento?->codigo === 'APROBADO';
    }

    public function anularDocumento(Documento $documento, User $usuario, string $motivo): void
    {
        if (! $this->puedeAnular($documento, $usuario)) {
            throw new DomainException('No puede eliminar este documento en el estado actual del proceso.');
        }
        if (trim($motivo) === '') {
            throw new DomainException('Indique el motivo para eliminar el documento.');
        }

        DB::select('select aplicar_transicion_documento(?, ?, ?, ?)', [$documento->id, 'anular', $usuario->id, trim($motivo)]);
    }

    /** El usuario es el analista de Compras asignado al proceso (vigente). */
    public function esAnalistaAsignado(Proceso $proceso, User $usuario): bool
    {
        return $usuario->tieneRol(self::ROL_QUE_CARGA_SIEMPRE)
            && $proceso->asignaciones()->where('user_id', $usuario->id)->where('activo', true)->exists();
    }

    /**
     * Nueva version de un documento existente:
     * - Analista asignado: mientras el proceso esta en revision (no en correccion); el estado del documento se conserva.
     * - Unidad requirente: en borrador, con observaciones o en correccion, y solo mientras el proceso es editable.
     */
    public function puedeAgregarVersion(Documento $documento, User $usuario): bool
    {
        $documento->loadMissing(['proceso.estadoProceso', 'proceso.estadoMacro', 'estadoDocumento', 'tipoDocumento']);
        $proceso = $documento->proceso;
        $estado = $documento->estadoDocumento?->codigo;

        if (! $usuario->activo || ! $usuario->tienePermiso('documentos.subir')
            || ! $this->puedeVerProceso($proceso, $usuario) || $proceso->estadoProceso?->es_final
            || $this->comisionSoloLectura($proceso, $usuario)) {
            return false;
        }

        if ($this->enRevisionTrasDevolucion($proceso)) {
            // Solo la unidad, solo los tipos editables y sin cambiar el estado del documento (ya aprobado)
            return $this->esUnidadDelProceso($usuario) && $documento->tipoDocumento->editable_tras_devolucion && $estado === 'APROBADO';
        }

        if ($this->esAnalistaAsignado($proceso, $usuario)) {
            // Con el proceso en correccion la tarea es de la unidad requirente: el analista no sube versiones.
            return ! $proceso->esEditable() && in_array($estado, Documento::ESTADOS_VERSION_ANALISTA, true);
        }

        return $proceso->esEditable() && in_array($estado, Documento::ESTADOS_VERSION_UNIDAD, true);
    }

    public function puedeDescargar(Documento $documento, User $usuario): bool
    {
        return $usuario->activo
            && $usuario->tienePermiso('documentos.descargar')
            && $this->puedeVerProceso($documento->proceso, $usuario);
    }

    /** Tipo "Otros documentos": habilitado en todos los estados salvo Inicio, para quien puede subir documentos. */
    public const TIPO_OTROS = 'OTROS';

    /** En el estado Inicio solo se cargan estos dos tipos. */
    public const TIPOS_EN_INICIO = ['INFORME_NECESIDAD', 'ESPECIFICACIONES_TECNICAS'];

    /** Tipos que el usuario puede cargar en este proceso: de la fase actual y de un rol generador suyo. */
    public function tiposDisponibles(Proceso $proceso, User $usuario): Collection
    {
        if (! $this->puedeSubir($proceso, $usuario)) {
            return collect();
        }

        $faseId = $proceso->estadoMacro?->fase_id;
        $rolesIds = $usuario->roles()->where('roles.activo', true)->pluck('roles.id');

        // un tipo que no admite varios documentos se oculta si el proceso ya tiene uno vigente (se le agregan versiones)
        $tiposYaCargados = Documento::query()
            ->where('proceso_id', $proceso->id)
            ->whereHas('estadoDocumento', fn ($query) => $query->where('grupo', '<>', 'anulado'))
            ->pluck('tipo_documento_id');

        $enInicio = $proceso->estadoProceso?->codigo === 'INICIO';

        return TipoDocumento::query()
            ->where('activo', true)
            ->when($enInicio, fn ($query) => $query->whereIn('codigo', self::TIPOS_EN_INICIO))
            // tras una devolucion la unidad solo corrige los tipos editables; "Otros documentos" siempre se puede agregar
            ->when($this->enRevisionTrasDevolucion($proceso), fn ($query) => $query->where(
                fn ($q) => $q->where('editable_tras_devolucion', true)->orWhere('codigo', self::TIPO_OTROS)
            ))
            ->where(fn ($query) => $query->where('permite_multiples', true)->orWhereNotIn('id', $tiposYaCargados))
            ->where(fn ($query) => $query->whereNull('fase_id')->orWhere('fase_id', $faseId))
            ->where(fn ($query) => $query->whereNull('rol_generador_id')->orWhereIn('rol_generador_id', $rolesIds))
            ->orderBy('nombre')
            ->pluck('nombre', 'id');
    }

    private function yaTieneDocumentoVigente(Proceso $proceso, TipoDocumento $tipo): bool
    {
        return ! $tipo->permite_multiples && Documento::query()
            ->where('proceso_id', $proceso->id)->where('tipo_documento_id', $tipo->id)
            ->whereHas('estadoDocumento', fn ($query) => $query->where('grupo', '<>', 'anulado'))
            ->exists();
    }

    // ---------------------------------------------------------------- limites

    /** Tamano maximo en MB: el menor entre el parametro global y el limite del tipo. */
    public function limiteMb(TipoDocumento $tipo): int
    {
        $global = DB::selectOne('select parametro_entero(?) as valor', ['tamano_maximo_documento_mb'])->valor;

        return (int) min($tipo->tamano_maximo_mb, $global ?: $tipo->tamano_maximo_mb);
    }

    // ---------------------------------------------------------------- carga

    /**
     * Crea un documento (en BORRADOR) con su primera version.
     *
     * @param  string  $rutaTemporal  ruta del archivo ya subido, dentro del disco "documentos"
     */
    public function crearDocumento(
        Proceso $proceso,
        TipoDocumento $tipo,
        ?string $titulo,
        string $rutaTemporal,
        string $nombreOriginal,
        ?string $comentario,
        User $usuario,
    ): Documento {
        try {
            if (! $this->tiposDisponibles($proceso, $usuario)->has($tipo->id)) {
                throw new DomainException(
                    $this->yaTieneDocumentoVigente($proceso, $tipo)
                        ? "Ya existe un documento de tipo \"{$tipo->nombre}\" en este proceso: use \"Nueva version\" para actualizarlo."
                        : 'No puede cargar documentos de este tipo en este proceso.'
                );
            }

            $documento = $this->guardar($proceso, $tipo, null, $titulo, $rutaTemporal, $nombreOriginal, $comentario, $usuario);

            // Los tipos sin revision (fichas, certificados...) quedan aprobados al cargarlos con el proceso ya devuelto.
            if (! $tipo->requiere_revision && $this->enRevisionTrasDevolucion($proceso)) {
                DB::select('select aplicar_transicion_documento(?, ?, ?, ?)', [$documento->id, 'registrar_sin_revision', $usuario->id, null]);
                $documento->refresh();
            }

            return $documento;
        } catch (Throwable $e) {
            Storage::disk(self::DISCO)->delete($rutaTemporal);
            throw $e;
        }
    }

    /** Agrega una version nueva (la numera la BD y pasa a ser la vigente). */
    public function agregarVersion(
        Documento $documento,
        string $rutaTemporal,
        string $nombreOriginal,
        ?string $comentario,
        User $usuario,
    ): Documento {
        try {
            $documento->loadMissing(['proceso.estadoProceso', 'proceso.estadoMacro', 'estadoDocumento', 'tipoDocumento']);

            if (! $this->puedeAgregarVersion($documento, $usuario)) {
                throw new DomainException('No puede cargar una version nueva de este documento en su estado actual.');
            }

            return $this->guardar($documento->proceso, $documento->tipoDocumento, $documento, null, $rutaTemporal, $nombreOriginal, $comentario, $usuario);
        } catch (Throwable $e) {
            Storage::disk(self::DISCO)->delete($rutaTemporal);
            throw $e;
        }
    }

    /**
     * Titulo de un documento nuevo: el que se haya digitado (si el formulario lo permite) o, en su lugar, el nombre del
     * archivo cargado (sin la extension). Si el nombre no sirve (vacio, mal codificado), el nombre del tipo de documento.
     */
    public function tituloDelDocumento(TipoDocumento $tipo, ?string $digitado, ?string $nombreOriginal): string
    {
        if (filled($digitado)) {
            return mb_substr(trim($digitado), 0, 250);
        }

        $nombre = trim((string) $nombreOriginal);
        if ($nombre !== '' && mb_check_encoding($nombre, 'UTF-8')) {
            $base = pathinfo(str_replace('\\', '/', $nombre), PATHINFO_FILENAME);
            $base = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $base) ?? '');
            if ($base !== '') {
                return mb_substr($base, 0, 250);
            }
        }

        return $tipo->nombre;
    }

    private function guardar(
        Proceso $proceso,
        TipoDocumento $tipo,
        ?Documento $documento,
        ?string $titulo,
        string $rutaTemporal,
        string $nombreOriginal,
        ?string $comentario,
        User $usuario,
    ): Documento {
        $extension = $this->validarArchivo($tipo, $rutaTemporal, $nombreOriginal);
        $disco = Storage::disk(self::DISCO);
        $rutaFinal = null;

        try {
            return DB::transaction(function () use ($proceso, $tipo, $documento, $titulo, $rutaTemporal, $nombreOriginal, $comentario, $usuario, $extension, $disco, &$rutaFinal) {
                $nuevo = $documento === null;

                if ($nuevo) {
                    $documento = new Documento(['titulo' => $this->tituloDelDocumento($tipo, $titulo, $nombreOriginal)]);
                    $documento->forceFill([
                        'proceso_id' => $proceso->id,
                        'tipo_documento_id' => $tipo->id,
                        'etapa_id' => $tipo->etapa_id,
                        'estado_documento_id' => EstadoDocumento::where('es_inicial', true)->value('id'),
                        'creado_por' => $usuario->id,
                    ])->save();
                }

                $archivo = $this->almacenar($proceso, $documento, $rutaTemporal, $nombreOriginal, $extension, $usuario);
                $rutaFinal = $archivo->ruta;

                // numero_version lo asigna el trigger de la BD; version_actual_id tambien.
                $version = new DocumentoVersion;
                $version->forceFill([
                    'documento_id' => $documento->id,
                    'numero_version' => 0,
                    'archivo_id' => $archivo->id,
                    'user_id' => $usuario->id,
                    'comentario' => filled($comentario) ? trim($comentario) : null,
                ])->save();

                DB::table('auditoria')->insert([
                    'user_id' => $usuario->id,
                    'evento' => $nuevo ? 'documento.creado' : 'documento.version_agregada',
                    'entidad' => 'documentos',
                    'entidad_id' => $documento->id,
                    'valores_nuevos' => json_encode([
                        'proceso_id' => $proceso->id,
                        'version_id' => $version->id,
                        'archivo' => $archivo->nombre_original,
                        'sha256' => $archivo->hash_sha256,
                    ]),
                ]);

                return $documento->fresh(['estadoDocumento', 'versionActual.archivo', 'tipoDocumento']);
            });
        } catch (Throwable $e) {
            // Si la BD revierte, no deben quedar archivos huerfanos en el disco.
            $disco->delete(array_filter([$rutaTemporal, $rutaFinal]));
            throw $e;
        }
    }

    /**
     * Mueve el archivo temporal a su ubicacion definitiva y lo registra en "archivos".
     * Las rutas creadas se recuerdan para poder limpiarlas si la operacion completa se revierte.
     */
    private function almacenar(Proceso $proceso, Documento $documento, string $rutaTemporal, string $nombreOriginal, string $extension, User $usuario): Archivo
    {
        $disco = Storage::disk(self::DISCO);
        $interno = Str::uuid()->toString().'.'.$extension;
        $ruta = "procesos/{$proceso->id}/documentos/{$documento->id}/{$interno}";
        $disco->move($rutaTemporal, $ruta);
        $this->rutasNuevas[] = $ruta;

        $archivo = new Archivo;
        $archivo->forceFill([
            'disco' => self::DISCO,
            'ruta' => $ruta,
            'nombre_original' => Str::limit(basename(str_replace('\\', '/', $nombreOriginal)), 250, ''),
            'nombre_interno' => $interno,
            'extension' => $extension,
            'mime' => $disco->mimeType($ruta) ?: 'application/octet-stream',
            'tamano_bytes' => $disco->size($ruta),
            'hash_sha256' => $this->hash($ruta),
            'user_id' => $usuario->id,
        ])->save();

        return $archivo;
    }

    /** @var array<int, string> archivos definitivos creados por este servicio en la operacion en curso */
    private array $rutasNuevas = [];

    /** Borra del disco los archivos definitivos creados en una operacion que se revirtio. */
    public function descartarRutasNuevas(): void
    {
        Storage::disk(self::DISCO)->delete($this->rutasNuevas);
        $this->rutasNuevas = [];
    }

    /**
     * Guarda un documento sin las comprobaciones de la pantalla de carga (las hace quien llama).
     * Lo usa la solicitud de publicacion para crear el memorando.
     */
    public function guardarInterno(
        Proceso $proceso,
        TipoDocumento $tipo,
        ?string $titulo,
        string $rutaTemporal,
        string $nombreOriginal,
        ?string $comentario,
        User $usuario,
    ): Documento {
        return $this->guardar($proceso, $tipo, null, $titulo, $rutaTemporal, $nombreOriginal, $comentario, $usuario);
    }

    /**
     * Guarda un PDF que ya llega legalizado (firmado) y lo deja en estado LEGALIZADO en un solo paso:
     * la unidad requirente usa 'legalizar' y el analista de compras 'registrar_legalizado' (memorando e informe de devolucion).
     * El PDF cargado es la version legalizada; la BD valida el permiso del usuario.
     */
    public function registrarLegalizadoDirecto(Proceso $proceso, TipoDocumento $tipo, ?string $titulo, string $rutaTemporal, string $nombreOriginal, User $usuario, string $transicionFinal = 'legalizar'): Documento
    {
        $documento = $this->guardarInterno($proceso, $tipo, $titulo, $rutaTemporal, $nombreOriginal, null, $usuario);
        $documento->refresh();

        DB::select('select aplicar_transicion_documento(?, ?, ?, ?)', [$documento->id, 'registrar_sin_revision', $usuario->id, null]);

        $archivoId = DB::table('documento_versiones')->where('id', $documento->version_actual_id)->value('archivo_id');
        DB::table('legalizaciones')->insert([
            'documento_version_id' => $documento->version_actual_id,
            'user_id' => $usuario->id,
            'archivo_id' => $archivoId,
        ]);
        DB::select('select aplicar_transicion_documento(?, ?, ?, ?)', [$documento->id, $transicionFinal, $usuario->id, null]);

        DB::table('auditoria')->insert([
            'user_id' => $usuario->id,
            'evento' => 'documento.legalizado',
            'entidad' => 'documentos',
            'entidad_id' => $documento->id,
            'valores_nuevos' => json_encode(['directo' => true, 'transicion' => $transicionFinal]),
        ]);

        return $documento;
    }

    /**
     * Legaliza un documento APROBADO con el PDF firmado electronicamente: guarda el archivo,
     * deja el registro en "legalizaciones" (sobre la version vigente) y aplica la transicion 'legalizar'.
     * La BD valida el permiso del usuario y el estado.
     */
    public function legalizarDocumento(Documento $documento, string $rutaTemporal, string $nombreOriginal, User $usuario, ?string $observacion = null): void
    {
        $documento->loadMissing(['proceso', 'tipoDocumento']);
        $extension = $this->validarArchivo($documento->tipoDocumento, $rutaTemporal, $nombreOriginal, ['pdf']);

        if ($documento->version_actual_id === null) {
            throw new DomainException("El documento \"{$documento->titulo}\" no tiene versiones.");
        }

        $archivo = $this->almacenar($documento->proceso, $documento, $rutaTemporal, $nombreOriginal, $extension, $usuario);

        DB::table('legalizaciones')->insert([
            'documento_version_id' => $documento->version_actual_id,
            'user_id' => $usuario->id,
            'archivo_id' => $archivo->id,
            'observacion' => filled($observacion) ? trim($observacion) : null,
        ]);

        DB::select('select aplicar_transicion_documento(?, ?, ?, ?)', [$documento->id, 'legalizar', $usuario->id, null]);

        DB::table('auditoria')->insert([
            'user_id' => $usuario->id,
            'evento' => 'documento.legalizado',
            'entidad' => 'documentos',
            'entidad_id' => $documento->id,
            'valores_nuevos' => json_encode(['archivo' => $archivo->nombre_original, 'sha256' => $archivo->hash_sha256]),
        ]);
    }

    /** Documentos del proceso que deben legalizarse y siguen APROBADOS (esperan su PDF firmado). */
    public function documentosPorLegalizar(Proceso $proceso): Collection
    {
        return Documento::query()
            ->with('estadoDocumento:id,codigo')
            ->where('proceso_id', $proceso->id)
            ->whereHas('estadoDocumento', fn ($query) => $query->where('codigo', 'APROBADO'))
            ->whereHas('tipoDocumento', fn ($query) => $query->where('requiere_legalizacion', true))
            ->orderBy('id')
            ->get();
    }

    /** @return string extension (minuscula) del archivo ya validado */
    private function validarArchivo(TipoDocumento $tipo, string $ruta, string $nombreOriginal, ?array $soloExtensiones = null): string
    {
        $disco = Storage::disk(self::DISCO);

        if (! $disco->exists($ruta)) {
            throw new DomainException('No se encontro el archivo cargado. Vuelva a subirlo.');
        }

        $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        $permitidas = $soloExtensiones ?? $tipo->extensiones();
        if ($extension === '' || ! in_array($extension, $permitidas, true)) {
            throw new DomainException('Tipo de archivo no permitido. Formatos aceptados: '.strtoupper(implode(', ', $permitidas)).'.');
        }

        $bytes = $disco->size($ruta);
        if ($bytes === 0) {
            throw new DomainException('El archivo esta vacio.');
        }
        $limite = $this->limiteMb($tipo);
        if ($bytes > $limite * 1024 * 1024) {
            throw new DomainException("El archivo supera el tamano maximo permitido ({$limite} MB).");
        }

        if ($extension === 'pdf') {
            $flujo = $disco->readStream($ruta);
            $cabecera = $flujo ? fread($flujo, 5) : '';
            if (is_resource($flujo)) {
                fclose($flujo);
            }
            if ($cabecera !== '%PDF-') {
                throw new DomainException('El archivo no es un PDF valido.');
            }
        }

        return $extension;
    }

    private function hash(string $ruta): string
    {
        $contexto = hash_init('sha256');
        $flujo = Storage::disk(self::DISCO)->readStream($ruta);
        hash_update_stream($contexto, $flujo);
        fclose($flujo);

        return hash_final($contexto);
    }

    // ---------------------------------------------------------------- estados

    /**
     * Envia a revision los documentos del proceso que exigen revision y tienen version:
     * - BORRADOR -> PENDIENTE_REVISION
     * - CON_OBSERVACIONES -> EN_CORRECCION -> PENDIENTE_REVISION
     * - EN_CORRECCION -> PENDIENTE_REVISION
     * Se ejecuta con el usuario que envia el proceso (lo valida la BD). Devuelve cuantos documentos envio.
     */
    public function enviarDocumentosARevision(Proceso $proceso, User $usuario): int
    {
        $documentos = Documento::query()
            ->with('estadoDocumento:id,codigo')
            ->where('proceso_id', $proceso->id)
            ->whereNotNull('version_actual_id')
            ->whereHas('estadoDocumento', fn ($query) => $query->whereIn('codigo', ['BORRADOR', 'CON_OBSERVACIONES', 'EN_CORRECCION']))
            ->whereHas('tipoDocumento', fn ($query) => $query->where('requiere_revision', true))
            ->orderBy('id')
            ->get();

        foreach ($documentos as $documento) {
            $pasos = match ($documento->estadoDocumento->codigo) {
                'BORRADOR' => ['enviar_a_revision'],
                'CON_OBSERVACIONES' => ['iniciar_correccion', 'reenviar'],
                default => ['reenviar'],
            };

            // Corregir exige estar asignado al documento: se crea para quien envia las correcciones.
            if (in_array('reenviar', $pasos, true)) {
                $this->registrarCorrecciones($documento, $usuario);
                $this->asegurarAsignacion($documento, $usuario, 'corregir');
            }

            foreach ($pasos as $paso) {
                DB::select('select aplicar_transicion_documento(?, ?, ?, ?)', [$documento->id, $paso, $usuario->id, null]);
            }

            $this->cerrarAsignaciones($documento, $usuario);
        }

        return $documentos->count();
    }

    /** Documentos del proceso que estan en revision (pendientes o en curso) y exigen revision. */
    public function documentosEnRevision(Proceso $proceso): Collection
    {
        return Documento::query()
            ->with('estadoDocumento:id,codigo')
            ->where('proceso_id', $proceso->id)
            ->whereNotNull('version_actual_id')
            ->whereHas('estadoDocumento', fn ($query) => $query->whereIn('codigo', ['PENDIENTE_REVISION', 'EN_REVISION']))
            ->whereHas('tipoDocumento', fn ($query) => $query->where('requiere_revision', true))
            ->orderBy('id')
            ->get();
    }

    /**
     * Remitir observaciones: los documentos elegidos pasan a CON_OBSERVACIONES y la unidad debe cargar una version
     * nueva de cada uno. Los no elegidos se entienden correctos y siguen en revision. $documentoIds = null
     * equivale a todos los que estan en revision. El comentario es opcional; sin el, se registra un texto
     * generico. Deja la revision y la observacion en sus tablas.
     */
    public function registrarObservaciones(Proceso $proceso, User $analista, ?string $comentario = null, ?array $documentoIds = null): int
    {
        $comentario = filled($comentario) ? trim($comentario) : 'Observaciones remitidas: revise y corrija el documento.';
        $documentos = $this->documentosEnRevision($proceso)
            ->when($documentoIds !== null, fn (Collection $c) => $c->whereIn('id', array_map('intval', $documentoIds)));

        if ($documentos->isEmpty()) {
            throw new DomainException('Seleccione al menos un documento en revision para remitir observaciones.');
        }

        foreach ($documentos as $documento) {
            $this->asegurarAsignacion($documento, $analista, 'revisar');
            $this->iniciarRevision($documento, $analista);

            $revisionId = $this->registrarRevision($documento, $analista, 'con_observaciones');
            DB::table('observaciones')->insert([
                'revision_id' => $revisionId,
                'tipo' => 'observacion',
                'texto' => $comentario,
                'user_id' => $analista->id,
            ]);

            DB::select('select aplicar_transicion_documento(?, ?, ?, ?)', [$documento->id, 'registrar_observaciones', $analista->id, $comentario]);
            $this->cerrarAsignaciones($documento, $analista);
        }

        return $documentos->count();
    }

    /** Validar documentacion: los documentos en revision quedan APROBADOS (con su revision y aprobacion). */
    public function aprobarDocumentos(Proceso $proceso, User $analista): int
    {
        $documentos = $this->documentosEnRevision($proceso);

        foreach ($documentos as $documento) {
            $this->asegurarAsignacion($documento, $analista, 'revisar');
            $this->iniciarRevision($documento, $analista);

            $this->registrarRevision($documento, $analista, 'aprobada');
            DB::table('aprobaciones')->insert([
                'documento_version_id' => $documento->version_actual_id,
                'user_id' => $analista->id,
            ]);

            DB::select('select aplicar_transicion_documento(?, ?, ?, ?)', [$documento->id, 'aprobar', $analista->id, null]);
            $this->cerrarAsignaciones($documento, $analista);
        }

        return $documentos->count();
    }

    private function iniciarRevision(Documento $documento, User $analista): void
    {
        if ($documento->estadoDocumento->codigo === 'PENDIENTE_REVISION') {
            DB::select('select aplicar_transicion_documento(?, ?, ?, ?)', [$documento->id, 'iniciar_revision', $analista->id, null]);
        }
    }

    private function registrarRevision(Documento $documento, User $analista, string $resultado): int
    {
        return DB::table('revisiones')->insertGetId([
            'documento_version_id' => $documento->version_actual_id,
            'numero_revision' => 0, // lo numera la BD
            'revisor_id' => $analista->id,
            'resultado' => $resultado,
            'fecha_fin' => DB::raw('now()'),
        ]);
    }

    /**
     * Al enviar las correcciones: cada observacion pendiente exige una version posterior a la observada
     * y queda registrada su correccion. Sin version nueva, no se puede enviar.
     */
    private function registrarCorrecciones(Documento $documento, User $usuario): void
    {
        $pendientes = DB::table('observaciones as ob')
            ->join('revisiones as rv', 'rv.id', '=', 'ob.revision_id')
            ->join('documento_versiones as dv', 'dv.id', '=', 'rv.documento_version_id')
            ->where('dv.documento_id', $documento->id)
            ->where('ob.tipo', 'observacion')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('correcciones as c')->whereColumn('c.observacion_id', 'ob.id'))
            ->select('ob.id', 'dv.numero_version')
            ->get();

        if ($pendientes->isEmpty()) {
            return;
        }

        $actual = DocumentoVersion::findOrFail($documento->version_actual_id);

        foreach ($pendientes as $pendiente) {
            if ($actual->numero_version <= $pendiente->numero_version) {
                throw new DomainException(
                    "El documento \"{$documento->titulo}\" tiene observaciones pendientes: cargue una nueva version antes de enviarlo a revision."
                );
            }
            DB::table('correcciones')->insert([
                'observacion_id' => $pendiente->id,
                'documento_version_id' => $actual->id,
                'user_id' => $usuario->id,
                'descripcion' => $actual->comentario ?: 'Version corregida',
            ]);
        }
    }

    private function asegurarAsignacion(Documento $documento, User $usuario, string $tarea): void
    {
        $existe = DB::table('asignaciones_documento')
            ->where('documento_id', $documento->id)->where('user_id', $usuario->id)
            ->where('tarea', $tarea)->where('activo', true)->exists();

        if (! $existe) {
            DB::table('asignaciones_documento')->insert([
                'documento_id' => $documento->id,
                'user_id' => $usuario->id,
                'tarea' => $tarea,
                'asignado_por' => $usuario->id,
            ]);
        }
    }

    private function cerrarAsignaciones(Documento $documento, User $usuario): void
    {
        DB::table('asignaciones_documento')
            ->where('documento_id', $documento->id)->where('user_id', $usuario->id)->where('activo', true)
            ->update(['activo' => false, 'fecha_fin' => DB::raw('GREATEST(now(), fecha_asignacion)')]);
    }

    // ---------------------------------------------------------------- descarga

    /** Descarga el PDF legalizado vigente de un documento LEGALIZADO. */
    public function descargarLegalizado(Documento $documento, User $usuario): StreamedResponse
    {
        $documento->loadMissing(['proceso', 'estadoDocumento']);

        if (! $this->puedeDescargar($documento, $usuario)) {
            abort(403, 'No tiene permiso para descargar este documento.');
        }

        $legalizacion = $documento->estadoDocumento?->codigo === 'LEGALIZADO' ? $documento->legalizacionVigente() : null;
        if ($legalizacion === null) {
            abort(404, 'El documento no tiene una version legalizada vigente.');
        }

        $archivo = $legalizacion->archivo;
        $disco = Storage::disk($archivo->disco);
        if (! $disco->exists($archivo->ruta)) {
            abort(404, 'El archivo ya no esta disponible.');
        }

        DB::table('auditoria')->insert([
            'user_id' => $usuario->id,
            'evento' => 'documento.legalizado_descargado',
            'entidad' => 'documentos',
            'entidad_id' => $documento->id,
            'valores_nuevos' => json_encode(['archivo_id' => $archivo->id]),
            'ip' => request()->ip(),
        ]);

        return $disco->download($archivo->ruta, $archivo->nombre_original);
    }

    public function descargar(DocumentoVersion $version, User $usuario): StreamedResponse
    {
        $version->loadMissing(['documento.proceso', 'archivo']);

        if (! $this->puedeDescargar($version->documento, $usuario)) {
            abort(403, 'No tiene permiso para descargar este documento.');
        }

        // Solo la ultima version (la vigente) se descarga; las anteriores quedan como registro de respaldo.
        if ($version->id !== $version->documento->version_actual_id) {
            abort(403, 'Solo la ultima version del documento esta disponible para descarga.');
        }

        $archivo = $version->archivo;
        $disco = Storage::disk($archivo->disco);
        if (! $disco->exists($archivo->ruta)) {
            abort(404, 'El archivo ya no esta disponible.');
        }

        DB::table('auditoria')->insert([
            'user_id' => $usuario->id,
            'evento' => 'documento.descargado',
            'entidad' => 'documentos',
            'entidad_id' => $version->documento_id,
            'valores_nuevos' => json_encode(['version_id' => $version->id, 'archivo_id' => $archivo->id]),
            'ip' => request()->ip(),
        ]);

        // Siempre como adjunto: el navegador nunca ejecuta ni muestra el contenido en la pagina.
        return $disco->download($archivo->ruta, $archivo->nombre_original);
    }
}
