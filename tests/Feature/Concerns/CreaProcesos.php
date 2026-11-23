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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Ayudas para armar procesos, asignaciones y documentos en las pruebas. */
trait CreaProcesos
{
    private function proceso(User $creador, string $descripcion = 'Medicamentos de prueba'): Proceso
    {
        $p = new Proceso(['tipo_proceso_id' => TipoProceso::value('id'), 'descripcion_producto' => $descripcion]);
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
}
