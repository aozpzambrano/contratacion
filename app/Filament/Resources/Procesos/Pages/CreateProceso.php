<?php

namespace App\Filament\Resources\Procesos\Pages;

use App\Filament\Resources\Procesos\ProcesoResource;
use App\Models\AsignacionProceso;
use App\Models\EstadoMacro;
use App\Models\EstadoProceso;
use App\Models\Fase;
use App\Models\Rol;
use Filament\Resources\Pages\CreateRecord;

class CreateProceso extends CreateRecord
{
    protected static string $resource = ProcesoResource::class;

    /** El proceso nace en la fase y estado iniciales del catalogo; un trigger registra el primer historial. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['creado_por'] = auth()->id();
        $data['estado_macro_id'] = EstadoMacro::where('codigo', 'PREPARATORIA')->value('id');
        $data['estado_proceso_id'] = EstadoProceso::where('es_inicial', true)->where('activo', true)->value('id');

        return $data;
    }

    /** Quien crea el proceso queda asignado como unidad requirente (permite enviar a revision). */
    protected function afterCreate(): void
    {
        AsignacionProceso::create([
            'proceso_id' => $this->record->id,
            'user_id' => auth()->id(),
            'rol_id' => Rol::where('codigo', 'UNIDAD_REQUIRENTE')->value('id'),
            'fase_id' => Fase::where('codigo', 'PREPARATORIA')->value('id'),
            'asignado_por' => auth()->id(),
        ]);
    }
}
