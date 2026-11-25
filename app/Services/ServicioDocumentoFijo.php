<?php

namespace App\Services;

use App\Models\Archivo;
use App\Models\DocumentoFijo;
use App\Models\DocumentoFijoVersion;
use App\Models\Proceso;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos fijos por tipo de proceso: los administra el administrador (reemplazo con respaldo)
 * y los ve Compras cuando el proceso esta listo para publicarse.
 */
class ServicioDocumentoFijo
{
    public const PERMISO_VER = 'expedientes.generar_publicacion';

    public const MAX_MB = 25;

    /** Estados en los que aun no se muestran los documentos fijos (la unidad todavia prepara o corrige su expediente). */
    private const ESTADOS_SIN_DOCUMENTOS_FIJOS = [
        'INICIO', 'EN_REVISION', 'EN_CORRECCION', 'PARA_REVISION', 'PARA_LEGALIZAR', 'PARA_REPUBLICAR', 'PARA_REAPERTURA',
    ];

    public function __construct(private readonly PlantillaWord $plantilla) {}

    /** El proceso ya esta en Compras para publicarse (o paso por alli): no cuando esta devuelto a la unidad. */
    public function estadoPermiteVer(Proceso $proceso): bool
    {
        $estado = $proceso->estadoProceso;
        if ($estado === null) {
            return false;
        }
        return ! in_array($estado->codigo, self::ESTADOS_SIN_DOCUMENTOS_FIJOS, true);
    }

    public function puedeVer(Proceso $proceso, User $usuario): bool
    {
        return $usuario->activo
            && $usuario->tienePermiso(self::PERMISO_VER)
            && app(ServicioDocumento::class)->puedeVerProceso($proceso, $usuario)
            && $this->estadoPermiteVer($proceso);
    }

    /** Documentos fijos del tipo de proceso con archivo cargado (los que se pueden descargar). */
    public function disponibles(Proceso $proceso): Collection
    {
        return DocumentoFijo::query()
            ->where('tipo_proceso_id', $proceso->tipo_proceso_id)
            ->where('activo', true)
            ->whereNotNull('archivo_id')
            ->with('archivo')
            ->orderBy('orden')
            ->get();
    }

    /** Valores con los que se rellena una plantilla para el proceso. */
    public function valoresPlantilla(Proceso $proceso): array
    {
        return [
            'objeto_contratacion' => (string) $proceso->objeto_contratacion,
            'numero_proceso' => (string) ($proceso->codigo ?? ''),
        ];
    }

    /**
     * Archivo listo para entregar: el original o, si es plantilla, una copia rellenada con los datos del proceso.
     *
     * @return array{0: string, 1: string, 2: bool} [ruta local, nombre de descarga, es temporal]
     */
    public function archivoParaProceso(DocumentoFijo $fijo, Proceso $proceso): array
    {
        $archivo = $fijo->archivo ?? throw new DomainException("El documento \"{$fijo->nombre}\" aun no tiene archivo cargado.");
        $disco = Storage::disk($archivo->disco);
        if (! $disco->exists($archivo->ruta)) {
            throw new DomainException("El archivo de \"{$fijo->nombre}\" ya no esta disponible.");
        }

        $base = Str::slug($fijo->nombre, '_');
        $local = $this->rutaLocal($archivo);

        if ($fijo->es_plantilla && $archivo->extension === 'docx') {
            $codigo = $proceso->codigo ? '_'.preg_replace('/[^A-Za-z0-9_-]/', '', $proceso->codigo) : '';

            return [$this->plantilla->rellenar($local, $this->valoresPlantilla($proceso)), "{$base}{$codigo}.docx", true];
        }

        return [$local, "{$base}.{$archivo->extension}", false];
    }

    /** Descarga para Compras (plantillas rellenadas). */
    public function descargar(DocumentoFijo $fijo, Proceso $proceso, User $usuario): StreamedResponse
    {
        if (! $this->puedeVer($proceso, $usuario) || $fijo->tipo_proceso_id !== $proceso->tipo_proceso_id || ! $fijo->activo) {
            abort(403, 'No tiene permiso para descargar este documento.');
        }

        [$ruta, $nombre, $temporal] = $this->archivoParaProceso($fijo, $proceso);

        DB::table('auditoria')->insert([
            'user_id' => $usuario->id,
            'evento' => 'documento_fijo.descargado',
            'entidad' => 'procesos',
            'entidad_id' => $proceso->id,
            'valores_nuevos' => json_encode(['documento_fijo_id' => $fijo->id, 'archivo_id' => $fijo->archivo_id]),
            'ip' => request()->ip(),
        ]);

        return $this->entregar($ruta, $nombre, $temporal);
    }

    /** Descarga de cualquier version (administrador): el archivo tal como se cargo. */
    public function descargarVersion(DocumentoFijoVersion $version, User $usuario): StreamedResponse
    {
        if (! $usuario->activo || ! $usuario->tienePermiso('catalogos.editar')) {
            abort(403, 'No tiene permiso para descargar este archivo.');
        }
        $archivo = $version->archivo;
        $disco = Storage::disk($archivo->disco);
        if (! $disco->exists($archivo->ruta)) {
            abort(404, 'El archivo ya no esta disponible.');
        }

        return $disco->download($archivo->ruta, $archivo->nombre_original);
    }

    public function entregar(string $ruta, string $nombre, bool $temporal): StreamedResponse
    {
        return response()->streamDownload(function () use ($ruta, $temporal) {
            readfile($ruta);
            if ($temporal) {
                @unlink($ruta);
            }
        }, $nombre);
    }

    /**
     * Carga o reemplaza el archivo vigente. La version anterior queda como respaldo (documentos_fijos_versiones).
     * La ruta temporal es la que dejo la subida del panel en el disco de documentos.
     */
    public function reemplazar(DocumentoFijo $fijo, string $rutaTemporal, string $nombreOriginal, User $usuario, ?string $comentario = null): void
    {
        if (! $usuario->activo || ! $usuario->tienePermiso('catalogos.editar')) {
            Storage::disk(ServicioDocumento::DISCO)->delete($rutaTemporal);
            throw new DomainException('No tiene permiso para modificar los documentos fijos.');
        }

        $disco = Storage::disk(ServicioDocumento::DISCO);
        $rutaFinal = null;

        try {
            $extension = $this->validar($fijo, $rutaTemporal, $nombreOriginal);

            DB::transaction(function () use ($fijo, $rutaTemporal, $nombreOriginal, $usuario, $comentario, $extension, $disco, &$rutaFinal) {
                $bloqueado = DocumentoFijo::query()->whereKey($fijo->id)->lockForUpdate()->firstOrFail();

                $interno = Str::uuid()->toString().'.'.$extension;
                $rutaFinal = "documentos_fijos/{$bloqueado->tipo_proceso_id}/{$bloqueado->codigo}/{$interno}";
                $disco->move($rutaTemporal, $rutaFinal);

                $archivo = new Archivo;
                $archivo->forceFill([
                    'disco' => ServicioDocumento::DISCO,
                    'ruta' => $rutaFinal,
                    'nombre_original' => Str::limit(basename(str_replace('\\', '/', $nombreOriginal)), 250, ''),
                    'nombre_interno' => $interno,
                    'extension' => $extension,
                    'mime' => $disco->mimeType($rutaFinal) ?: 'application/octet-stream',
                    'tamano_bytes' => $disco->size($rutaFinal),
                    'hash_sha256' => hash('sha256', $disco->get($rutaFinal)),
                    'user_id' => $usuario->id,
                ])->save();

                $numero = (int) DB::table('documentos_fijos_versiones')->where('documento_fijo_id', $bloqueado->id)->max('numero_version') + 1;
                DB::table('documentos_fijos_versiones')->insert([
                    'documento_fijo_id' => $bloqueado->id,
                    'numero_version' => $numero,
                    'archivo_id' => $archivo->id,
                    'user_id' => $usuario->id,
                    'comentario' => filled($comentario) ? trim($comentario) : null,
                ]);

                DB::table('documentos_fijos')->where('id', $bloqueado->id)->update(['archivo_id' => $archivo->id, 'updated_at' => DB::raw('now()')]);

                DB::table('auditoria')->insert([
                    'user_id' => $usuario->id,
                    'evento' => 'documento_fijo.reemplazado',
                    'entidad' => 'documentos_fijos',
                    'entidad_id' => $bloqueado->id,
                    'valores_nuevos' => json_encode(['archivo_id' => $archivo->id, 'version' => $numero]),
                    'ip' => request()->ip(),
                ]);
            });
        } catch (\Throwable $e) {
            $disco->delete($rutaTemporal);
            if ($rutaFinal !== null) {
                $disco->delete($rutaFinal);
            }
            throw $e;
        }
    }

    /** @return string extension (minuscula) */
    private function validar(DocumentoFijo $fijo, string $ruta, string $nombreOriginal): string
    {
        $disco = Storage::disk(ServicioDocumento::DISCO);
        if (! $disco->exists($ruta)) {
            throw new DomainException('No se encontro el archivo cargado. Vuelva a subirlo.');
        }

        $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        $permitidas = $fijo->extensiones();
        if ($extension === '' || ! in_array($extension, $permitidas, true)) {
            throw new DomainException('Tipo de archivo no permitido. Formatos aceptados: '.strtoupper(implode(', ', $permitidas)).'.');
        }

        $bytes = $disco->size($ruta);
        if ($bytes === 0) {
            throw new DomainException('El archivo esta vacio.');
        }
        if ($bytes > self::MAX_MB * 1024 * 1024) {
            throw new DomainException('El archivo supera el tamano maximo permitido ('.self::MAX_MB.' MB).');
        }

        $cabecera = substr($disco->get($ruta), 0, 8);
        $valido = match ($extension) {
            'pdf' => str_starts_with($cabecera, '%PDF-'),
            'docx' => str_starts_with($cabecera, "PK\x03\x04"),
            'doc' => str_starts_with($cabecera, "\xD0\xCF\x11\xE0"),
            default => true,
        };
        if (! $valido) {
            throw new DomainException('El contenido del archivo no corresponde al formato '.strtoupper($extension).'.');
        }

        if ($fijo->es_plantilla) {
            $this->validarPlantilla($ruta);
        }

        return $extension;
    }

    /** La plantilla debe conservar los marcadores que el sistema rellena. */
    private function validarPlantilla(string $ruta): void
    {
        $local = tempnam(sys_get_temp_dir(), 'val_');
        file_put_contents($local, Storage::disk(ServicioDocumento::DISCO)->get($ruta));
        try {
            $presentes = $this->plantilla->marcadoresPresentes($local);
        } finally {
            @unlink($local);
        }

        $faltan = array_diff(PlantillaWord::MARCADORES, $presentes);
        if ($faltan !== []) {
            throw new DomainException('La plantilla debe conservar los marcadores '.implode(', ', array_map(fn ($m) => '{{'.$m.'}}', PlantillaWord::MARCADORES)).'. Faltan: '.implode(', ', array_map(fn ($m) => '{{'.$m.'}}', $faltan)).'.');
        }
    }

    /** Ruta local de un archivo almacenado (copia temporal si el disco no es local). */
    public function rutaLocal(Archivo $archivo): string
    {
        $disco = Storage::disk($archivo->disco);
        $local = tempnam(sys_get_temp_dir(), 'fijo_');
        file_put_contents($local, $disco->get($archivo->ruta));
        register_shutdown_function(fn () => @unlink($local));

        return $local;
    }
}
