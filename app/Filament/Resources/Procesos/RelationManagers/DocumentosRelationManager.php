<?php

namespace App\Filament\Resources\Procesos\RelationManagers;

use App\Filament\Resources\Procesos\Pages\ViewProceso;
use App\Models\Documento;
use App\Models\TipoDocumento;
use App\Services\ServicioDocumento;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentosRelationManager extends RelationManager
{
    protected static string $relationship = 'documentos';

    protected static ?string $title = 'Documentos';

    // Se carga junto con la pagina (sin esperar a una accion del usuario).
    protected static bool $isLazy = false;

    // En la pagina "Ver" los gestores de relacion son de solo lectura por defecto; aqui las acciones
    // se habilitan o no segun ServicioDocumento (permiso, fase y estado), no segun la pagina.
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $servicio = app(ServicioDocumento::class);

        return $table
            ->recordTitleAttribute('titulo')
            ->modifyQueryUsing(fn ($query) => $query->with(['tipoDocumento', 'estadoDocumento', 'versionActual', 'creador']))
            ->defaultSort('id')
            ->emptyStateHeading('Aún no hay documentos cargados')
            ->columns([
                TextColumn::make('tipoDocumento.nombre')->label('Tipo'),
                TextColumn::make('titulo')->label('Título')->wrap(),
                TextColumn::make('estadoDocumento.nombre')->label('Estado')->badge(),
                TextColumn::make('versionActual.numero_version')->label('Versión')->prefix('v'),
                TextColumn::make('creador.name')->label('Cargado por'),
                TextColumn::make('updated_at')->label('Actualizado')->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([
                Action::make('subir')
                    ->label('Subir documento')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn () => $servicio->tiposDisponibles($this->getOwnerRecord(), auth()->user())->isNotEmpty())
                    ->modalHeading('Subir documento')
                    ->schema([
                        Select::make('tipo_documento_id')
                            ->label('Tipo de documento')
                            ->options(fn () => $servicio->tiposDisponibles($this->getOwnerRecord(), auth()->user()))
                            ->required(),
                        TextInput::make('titulo')
                            ->label('Título')
                            ->maxLength(250)
                            ->helperText('Opcional: si lo deja vacío se usa el nombre del tipo.'),
                        $this->campoArchivo(),
                        Textarea::make('comentario')->label('Comentario de la versión')->rows(2),
                    ])
                    ->action(function (array $data) use ($servicio) {
                        $this->ejecutar(function () use ($servicio, $data) {
                            [$ruta, $nombre] = $this->archivoSubido($data);
                            $servicio->crearDocumento(
                                $this->getOwnerRecord(),
                                TipoDocumento::findOrFail($data['tipo_documento_id']),
                                $data['titulo'] ?? null,
                                $ruta,
                                $nombre,
                                $data['comentario'] ?? null,
                                auth()->user(),
                            );
                        }, 'Documento cargado');
                        $this->recargarPagina();
                    }),
            ])
            ->recordActions([
                Action::make('versiones')
                    ->label('Versiones')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (Documento $record) => $record->titulo)
                    ->modalContent(fn (Documento $record) => view('documentos.versiones', ['documento' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar'),
                Action::make('descargar')
                    ->label('Descargar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Documento $record) => $record->version_actual_id !== null
                        && $servicio->puedeDescargar($record, auth()->user()))
                    ->url(fn (Documento $record) => route('documentos.descargar', $record->version_actual_id)),
                Action::make('nueva_version')
                    // El analista "sube" su documento corregido; la unidad requirente carga una "nueva versión".
                    ->label(fn () => $servicio->esAnalistaAsignado($this->getOwnerRecord(), auth()->user()) ? 'Subir documento' : 'Nueva versión')
                    ->icon('heroicon-o-document-plus')
                    ->color('warning')
                    ->visible(fn (Documento $record) => $servicio->puedeAgregarVersion($record, auth()->user()))
                    ->schema([
                        $this->campoArchivo(),
                        Textarea::make('comentario')->label('Qué cambió en esta versión')->rows(2),
                    ])
                    ->action(function (array $data, Documento $record) use ($servicio) {
                        $this->ejecutar(function () use ($servicio, $data, $record) {
                            [$ruta, $nombre] = $this->archivoSubido($data);
                            $servicio->agregarVersion($record, $ruta, $nombre, $data['comentario'] ?? null, auth()->user());
                        }, 'Nueva versión cargada');
                        $this->recargarPagina();
                    }),
            ]);
    }

    /** El archivo queda en el disco privado, en "temporal/"; ServicioDocumento lo valida y lo mueve. */
    private function campoArchivo(): FileUpload
    {
        return FileUpload::make('archivo')
            ->label('Archivo (PDF, Word o Excel)')
            ->disk(ServicioDocumento::DISCO)
            ->directory('temporal')
            ->visibility('private')
            ->storeFileNamesIn('nombre_original')
            ->helperText('Tamaño máximo según la configuración del sistema (por defecto 20 MB).')
            ->required();
    }

    /** @return array{0: string, 1: string} ruta temporal y nombre original */
    private function archivoSubido(array $data): array
    {
        $ruta = is_array($data['archivo']) ? (string) array_values($data['archivo'])[0] : (string) $data['archivo'];
        $nombre = $data['nombre_original'] ?? basename($ruta);

        return [$ruta, is_array($nombre) ? (string) array_values($nombre)[0] : (string) $nombre];
    }

    private bool $huboError = false;

    private function ejecutar(callable $accion, string $mensajeExito): void
    {
        $this->huboError = false;

        try {
            $accion();
        } catch (DomainException $e) {
            $this->huboError = true;
            Notification::make()->title('No se pudo completar la acción')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($mensajeExito)->success()->send();
    }

    /**
     * Tras cargar un documento se recarga toda la pagina del proceso: asi los botones de arriba
     * ("Enviar a revisión", "Editar", ...) se recalculan sin que el usuario deba salir y volver a entrar.
     */
    private function recargarPagina(): void
    {
        if (! $this->huboError) {
            $this->redirect(ViewProceso::getUrl(['record' => $this->getOwnerRecord()]), navigate: false);
        }
    }
}
