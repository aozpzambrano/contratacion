<?php

namespace Database\Seeders;

use App\Models\DocumentoFijo;
use App\Models\User;
use App\Services\ServicioDocumento;
use App\Services\ServicioDocumentoFijo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Carga los archivos iniciales de los documentos fijos (database/seeders/documentos_fijos/<CODIGO>.<ext>).
 * Solo completa los que aun no tienen archivo: no pisa lo que el administrador ya haya reemplazado.
 * Despues se reemplazan desde Administracion > Documentos fijos del proceso.
 */
class DocumentosFijosSeeder extends Seeder
{
    public function run(): void
    {
        $usuario = User::query()->whereHas('roles', fn ($q) => $q->where('roles.codigo', 'ADMIN'))->orderBy('id')->first();
        if ($usuario === null) {
            $this->command?->warn('Documentos fijos: no hay un administrador; ejecute primero UsuarioAdministradorSeeder.');

            return;
        }

        $servicio = app(ServicioDocumentoFijo::class);
        $disco = Storage::disk(ServicioDocumento::DISCO);

        foreach (DocumentoFijo::query()->whereNull('archivo_id')->orderBy('id')->get() as $fijo) {
            foreach ($fijo->extensiones() as $extension) {
                $origen = __DIR__."/documentos_fijos/{$fijo->codigo}.{$extension}";
                if (! is_file($origen)) {
                    continue;
                }
                $temporal = 'temporal/'.Str::uuid().'.tmp';
                $disco->put($temporal, file_get_contents($origen));
                $servicio->reemplazar($fijo, $temporal, basename($origen), $usuario, 'Carga inicial');
                $this->command?->info("Documento fijo cargado: {$fijo->nombre}");

                break;
            }
        }
    }
}
