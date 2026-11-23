<?php

namespace App\Filament\Resources\Procesos\Pages;

use App\Filament\Resources\Procesos\ProcesoResource;
use App\Models\User;
use App\Services\ServicioProceso;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewProceso extends ViewRecord
{
    protected static string $resource = ProcesoResource::class;

    protected function getHeaderActions(): array
    {
        $servicio = app(ServicioProceso::class);
        $usuario = auth()->user();
        $proceso = $this->getRecord();
        $acciones = [];

        // Transiciones del flujo disponibles para este usuario en el estado actual
        foreach ($servicio->transicionesDisponibles($proceso, $usuario) as $transicion) {
            $acciones[] = Action::make('transicion_'.$transicion->codigo)
                ->label($transicion->nombre)
                ->color($transicion->requiere_comentario ? 'warning' : 'primary')
                ->modalHeading($transicion->nombre)
                ->modalSubmitActionLabel('Confirmar')
                ->schema([
                    Textarea::make('comentario')
                        ->label($transicion->requiere_comentario ? 'Comentario (obligatorio)' : 'Comentario (opcional)')
                        ->required($transicion->requiere_comentario),
                ])
                ->action(function (array $data) use ($servicio, $proceso, $transicion, $usuario) {
                    $this->ejecutar(fn () => $servicio->aplicarTransicion($proceso, $transicion, $usuario, $data['comentario'] ?? null),
                        'Estado actualizado: '.$transicion->estadoDestino->nombre);
                });
        }

        // Compras genera el codigo antes de publicar
        if ($servicio->puedeGenerarCodigo($proceso, $usuario)) {
            $acciones[] = Action::make('generar_codigo')
                ->label('Generar código del proceso')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Se asignará el siguiente número disponible. El código no podrá modificarse.')
                ->action(function () use ($servicio, $proceso, $usuario) {
                    $this->ejecutar(fn () => $servicio->generarCodigo($proceso, $usuario), 'Código del proceso generado');
                });
        }

        // El jefe de Compras asigna al analista responsable
        if ($servicio->puedeAsignarAnalista($proceso, $usuario)) {
            $acciones[] = Action::make('asignar_analista')
                ->label('Asignar analista')
                ->color('gray')
                ->schema([
                    Select::make('analista_id')
                        ->label('Analista de Compras')
                        ->options(fn () => $servicio->analistasDisponibles())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data) use ($servicio, $proceso, $usuario) {
                    $this->ejecutar(fn () => $servicio->asignarAnalista(
                        $proceso, User::findOrFail($data['analista_id']), $usuario
                    ), 'Analista asignado');
                });
        }

        $acciones[] = EditAction::make();

        return $acciones;
    }

    /** Ejecuta una accion del servicio y avisa del resultado; el error de negocio se muestra tal cual. */
    private function ejecutar(callable $accion, string $mensajeExito): void
    {
        try {
            $accion();
        } catch (DomainException $e) {
            Notification::make()->title('No se pudo completar la acción')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($mensajeExito)->success()->send();
        $this->redirect(ProcesoResource::getUrl('view', ['record' => $this->getRecord()]));
    }
}
