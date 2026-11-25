<?php

namespace App\Filament\Resources\DocumentosFijos\Pages;

use App\Filament\Resources\DocumentosFijos\DocumentoFijoResource;
use App\Services\ServicioDocumentoFijo;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditDocumentoFijo extends EditRecord
{
    protected static string $resource = DocumentoFijoResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $ruta = Arr::first((array) ($data['nuevo_archivo'] ?? []));
        $nombres = (array) ($data['nombre_archivo'] ?? []);
        $comentario = $data['comentario_version'] ?? null;
        unset($data['nuevo_archivo'], $data['nombre_archivo'], $data['comentario_version']);

        // Primero el archivo: si no es valido no se guarda nada.
        if ($ruta) {
            $original = (string) ($nombres[$ruta] ?? Arr::first($nombres) ?? basename((string) $ruta));
            try {
                app(ServicioDocumentoFijo::class)->reemplazar($record, (string) $ruta, $original, auth()->user(), $comentario);
            } catch (DomainException $e) {
                Notification::make()->title('No se pudo reemplazar el archivo')->body($e->getMessage())->danger()->send();

                throw ValidationException::withMessages(['data.nuevo_archivo' => $e->getMessage()]);
            }
        }

        $record->fill($data)->save();

        return $record;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Documento fijo actualizado';
    }
}
