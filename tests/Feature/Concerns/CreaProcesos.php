<?php

namespace Tests\Feature\Concerns;

use App\Models\AsignacionProceso;
use App\Models\EstadoMacro;
use App\Models\EstadoProceso;
use App\Models\Fase;
use App\Models\Proceso;
use App\Models\Rol;
use App\Models\TipoProceso;
use App\Models\User;
use App\Services\ServicioDocumento;
use App\Services\ServicioLegalizacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Ayudas para armar procesos, asignaciones y documentos en las pruebas. */
trait CreaProcesos
{
    private function proceso(User $creador, string $descripcion = 'Medicamentos de prueba'): Proceso
    {
        $p = new Proceso(['tipo_proceso_id' => TipoProceso::value('id'), 'objeto_contratacion' => $descripcion]);
        $p->forceFill([
            'creado_por' => $creador->id,
            'estado_macro_id' => EstadoMacro::where('codigo', 'PREPARATORIA')->value('id'),
            'estado_proceso_id' => EstadoProceso::where('codigo', 'INICIO')->value('id'),
        ])->save();

        return $p;
    }

    private function asignar(Proceso $p, User $usuario, string $rol, User $por): void
    {
        AsignacionProceso::create([
            'proceso_id' => $p->id,
            'user_id' => $usuario->id,
            'rol_id' => Rol::where('codigo', $rol)->value('id'),
            'fase_id' => Fase::where('codigo', 'PREPARATORIA')->value('id'),
            'asignado_por' => $por->id,
        ]);
    }

    /** Carga un documento con una version (archivo) para cumplir los requisitos de la transicion. */
    private function cargarDocumento(Proceso $p, string $tipo, User $usuario): void
    {
        $documento = DB::table('documentos')->insertGetId([
            'proceso_id' => $p->id,
            'tipo_documento_id' => DB::table('tipos_documento')->where('codigo', $tipo)->value('id'),
            'estado_documento_id' => DB::table('estados_documento')->where('codigo', 'BORRADOR')->value('id'),
            'titulo' => $tipo,
            'creado_por' => $usuario->id,
        ]);
        $archivo = DB::table('archivos')->insertGetId([
            'disco' => 'documentos',
            'ruta' => 'pruebas/'.$documento.'.pdf',
            'nombre_original' => $tipo.'.pdf',
            'nombre_interno' => Str::uuid().'.pdf',
            'extension' => 'pdf',
            'mime' => 'application/pdf',
            'tamano_bytes' => 10,
            'hash_sha256' => str_repeat('a', 64),
            'user_id' => $usuario->id,
        ]);
        DB::table('documento_versiones')->insert([
            'documento_id' => $documento, 'numero_version' => 0, 'archivo_id' => $archivo, 'user_id' => $usuario->id,
        ]);
    }

    /** Deja un PDF temporal en el disco (falso) y devuelve [ruta, nombre original], como lo hace la pantalla. */
    private function pdfTemporal(string $nombre, string $contenido = "%PDF-1.4\ncontenido"): array
    {
        $ruta = 'temporal/'.Str::uuid().'.tmp';
        Storage::disk(ServicioDocumento::DISCO)->put($ruta, $contenido);

        return [$ruta, $nombre];
    }

    /**
     * La unidad requirente remite los documentos legalizados (PARA_LEGALIZAR): PDF firmados de lo que falta
     * legalizar, memorando y datos de la solicitud. Requiere Storage::fake del disco "documentos".
     */
    private function remitirLegalizados(Proceso $p, User $unidad, array $horas = [24, 48], string $numero = 'AIL-GER-2026-001-M', array $distributivos = []): void
    {
        $legalizados = [];
        foreach (app(ServicioDocumento::class)->documentosPorLegalizar($p) as $documento) {
            $legalizados[$documento->id] = $this->pdfTemporal(Str::slug($documento->titulo, '_').'_firmado.pdf');
        }

        // fichas tecnicas, certificados, etc. que aun no tiene el proceso (dos archivos de fichas tecnicas)
        $adicionales = [];
        foreach (app(ServicioLegalizacion::class)->tiposAdicionalesPendientes($p) as $tipo) {
            if ($tipo->codigo === 'DISTRIBUTIVO' && $distributivos !== []) {
                continue; // el Distributivo se entrega por su propio campo
            }
            $adicionales[$tipo->id] = [$this->pdfTemporal($tipo->codigo.'_1.pdf')];
            if ($tipo->codigo === 'FICHAS_TECNICAS') {
                $adicionales[$tipo->id][] = $this->pdfTemporal($tipo->codigo.'_2.pdf');
            }
        }

        app(ServicioLegalizacion::class)->remitir($p->fresh(), $unidad, $legalizados, $this->pdfTemporal('memorando.pdf'), $numero, $horas, $adicionales, $distributivos);
    }
}
