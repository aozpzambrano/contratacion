<?php

namespace App\Services;

use App\Models\Documento;
use App\Models\Proceso;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * "Generar Expediente Publicacion": un .zip con los documentos del proceso y los documentos fijos que Compras elija.
 * Documentos del proceso: la version vigente (o el PDF legalizado si lo tienen). Plantillas: rellenadas con el proceso.
 */
class ServicioExpedientePublicacion
{
    /** Tipos que NO vienen marcados por defecto (siguen disponibles para marcarlos). */
    public const TIPOS_SIN_MARCAR = ['VALIDACION_FIRMAS_ELECTRONICAS', 'AUTORIZACION_REQUERIMIENTO'];

    /**
     * Carpetas del zip por tipo de documento, en este orden. Lo que no figura aqui (memorando de solicitud de
     * publicacion, otros documentos y los documentos fijos) va suelto, sin numeracion y sin carpeta.
     */
    public const CARPETAS = [
        'INFORME_NECESIDAD' => '01. Informe de Necesidad',
        'ESPECIFICACIONES_TECNICAS' => '02. Especificaciones Técnicas',
        'DISTRIBUTIVO' => '03. Distributivo',
        'FICHAS_TECNICAS' => '04. Fichas',
        'CERTIFICADO_NO_CATALOGADO' => '05. Certificado de no catalogado',
        'VALIDACION_FIRMAS_ELECTRONICAS' => '06. Validación de Firmas',
        'AUTORIZACION_REQUERIMIENTO' => '07. Autorización del Requerimiento',
    ];

    public function __construct(
        private readonly ServicioDocumentoFijo $fijos,
        private readonly ServicioDocumento $documentos,
    ) {}

    public function puedeGenerar(Proceso $proceso, User $usuario): bool
    {
        return $this->fijos->puedeVer($proceso, $usuario);
    }

    /**
     * Elementos que se pueden incluir, en orden: documentos del proceso y luego los fijos.
     *
     * @return Collection<string, array{clave: string, etiqueta: string, marcado: bool}>
     */
    public function opciones(Proceso $proceso): Collection
    {
        $opciones = collect();

        $docs = Documento::query()
            ->where('proceso_id', $proceso->id)
            ->whereHas('estadoDocumento', fn ($q) => $q->where('codigo', '!=', 'ANULADO'))
            ->whereNotNull('version_actual_id')
            ->with(['tipoDocumento', 'estadoDocumento', 'versionActual.archivo'])
            ->orderBy('id')
            ->get();

        foreach ($docs as $doc) {
            $nombreTipo = $doc->tipoDocumento->nombre;
            $etiqueta = ($doc->titulo && $doc->titulo !== $nombreTipo && $doc->titulo !== $doc->tipoDocumento->codigo)
                ? "{$nombreTipo}: {$doc->titulo}" : $nombreTipo;
            $opciones->put("doc_{$doc->id}", [
                'clave' => "doc_{$doc->id}",
                'etiqueta' => $etiqueta,
                'marcado' => ! in_array($doc->tipoDocumento->codigo, self::TIPOS_SIN_MARCAR, true),
            ]);
        }

        foreach ($this->fijos->disponibles($proceso) as $fijo) {
            $opciones->put("fijo_{$fijo->id}", [
                'clave' => "fijo_{$fijo->id}",
                'etiqueta' => $fijo->nombre.($fijo->es_plantilla ? ' (se completa con los datos del proceso)' : ''),
                'marcado' => true,
            ]);
        }

        return $opciones;
    }

    /** @return array<int, string> claves marcadas por defecto */
    public function marcadasPorDefecto(Proceso $proceso): array
    {
        return $this->opciones($proceso)->where('marcado', true)->keys()->all();
    }

    /**
     * Arma el zip con los elementos elegidos.
     *
     * @param  array<int, string>  $claves
     * @return array{0: string, 1: string} [ruta del zip temporal, nombre de descarga]
     */
    public function generar(Proceso $proceso, User $usuario, array $claves): array
    {
        if (! $this->puedeGenerar($proceso, $usuario)) {
            throw new DomainException('No puede generar el expediente de publicación en este momento.');
        }

        $disponibles = $this->opciones($proceso);
        $claves = array_values(array_filter($claves, fn ($c) => $disponibles->has($c)));
        if ($claves === []) {
            throw new DomainException('Seleccione al menos un documento para el expediente.');
        }

        $ruta = tempnam(sys_get_temp_dir(), 'expediente_');
        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new DomainException('No se pudo crear el archivo zip.');
        }

        $temporales = [];
        $usados = [];
        try {
            foreach ($disponibles->keys()->intersect($claves) as $clave) {
                [$contenido, $nombre, $temporal, $carpeta] = str_starts_with($clave, 'doc_')
                    ? $this->documentoDelProceso((int) substr($clave, 4), $proceso)
                    : $this->documentoFijo((int) substr($clave, 5), $proceso);
                if ($temporal) {
                    $temporales[] = $contenido;
                }

                $nombreZip = $this->nombreUnico(($carpeta ? $carpeta.'/' : '').$nombre, $usados);
                $usados[] = $nombreZip;
                $zip->addFile($contenido, $nombreZip);
            }
            $zip->close();
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($ruta);
            throw $e;
        } finally {
            // addFile lee el archivo al cerrar el zip: los temporales se borran despues.
            foreach ($temporales as $t) {
                @unlink($t);
            }
        }

        DB::table('auditoria')->insert([
            'user_id' => $usuario->id,
            'evento' => 'expediente.publicacion_generado',
            'entidad' => 'procesos',
            'entidad_id' => $proceso->id,
            'valores_nuevos' => json_encode(['elementos' => $claves]),
            'ip' => request()->ip(),
        ]);

        $codigo = $proceso->codigo ? preg_replace('/[^A-Za-z0-9_-]/', '', $proceso->codigo) : 'proceso_'.$proceso->id;

        return [$ruta, "Expediente_Publicacion_{$codigo}.zip"];
    }

    /** @return array{0: string, 1: string, 2: bool, 3: ?string} [ruta local, nombre, temporal, carpeta] */
    private function documentoDelProceso(int $id, Proceso $proceso): array
    {
        $doc = Documento::query()->where('proceso_id', $proceso->id)->with(['tipoDocumento', 'estadoDocumento', 'versionActual.archivo'])->findOrFail($id);

        $archivo = $doc->estadoDocumento->codigo === 'LEGALIZADO' ? $doc->legalizacionVigente()?->archivo : null;
        $archivo ??= $doc->versionActual->archivo;

        $disco = Storage::disk($archivo->disco);
        if (! $disco->exists($archivo->ruta)) {
            throw new DomainException("El archivo de \"{$doc->tipoDocumento->nombre}\" ya no está disponible.");
        }

        $local = tempnam(sys_get_temp_dir(), 'exp_');
        file_put_contents($local, $disco->get($archivo->ruta));

        $base = Str::slug($doc->tipoDocumento->nombre.($doc->titulo && $doc->titulo !== $doc->tipoDocumento->nombre && $doc->titulo !== $doc->tipoDocumento->codigo ? '_'.$doc->titulo : ''), '_');

        return [$local, Str::limit($base, 120, '').'.'.$archivo->extension, true, self::CARPETAS[$doc->tipoDocumento->codigo] ?? null];
    }

    /** @return array{0: string, 1: string, 2: bool, 3: ?string} */
    private function documentoFijo(int $id, Proceso $proceso): array
    {
        $fijo = \App\Models\DocumentoFijo::query()->where('tipo_proceso_id', $proceso->tipo_proceso_id)->with('archivo')->findOrFail($id);
        [$ruta, $nombre] = $this->fijos->archivoParaProceso($fijo, $proceso);

        // La copia local es siempre un temporal propio de este zip.
        return [$ruta, $nombre, true, null];
    }

    private function nombreUnico(string $nombre, array $usados): string
    {
        if (! in_array($nombre, $usados, true)) {
            return $nombre;
        }
        $info = pathinfo($nombre);

        return $info['filename'].'_'.(count($usados) + 1).'.'.($info['extension'] ?? '');
    }
}
