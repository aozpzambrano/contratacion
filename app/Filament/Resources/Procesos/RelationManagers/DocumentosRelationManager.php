<?php

namespace App\Filament\Resources\Procesos\RelationManagers;

use App\Filament\Resources\Procesos\Pages\ViewProceso;
use App\Models\Documento;
use App\Models\TipoDocumento;
use App\Services\ServicioDocumento;
use App\Services\ServicioDocumentoFijo;
use App\Services\ServicioExpedientePublicacion;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
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
            // la relacion ya ordena por id: se descarta ese orden para seguir el del expediente de publicacion
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['tipoDocumento', 'estadoDocumento', 'versionActual', 'creador'])
                // los documentos eliminados (anulados) ya no se listan; quedan en el historial de estados
                ->whereHas('estadoDocumento', fn ($e) => $e->where('grupo', '<>', 'anulado'))
                ->reorder()
                ->orderByRaw(ServicioExpedientePublicacion::sqlPosicionTipo())
                ->orderBy('documentos.id'))
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
                    // en Inicio los documentos se cargan desde "Enviar a revision"
                    ->visible(fn () => $this->getOwnerRecord()->estadoProceso?->codigo !== 'INICIO'
                        && $servicio->tiposDisponibles($this->getOwnerRecord(), auth()->user())->isNotEmpty())
                    ->modalHeading('Subir documento')
                    ->schema([
                        Select::make('tipo_documento_id')
                            ->label('Tipo de documento')
                            ->options(fn () => $servicio->tiposDisponibles($this->getOwnerRecord(), auth()->user()))
                            ->live()
                            ->afterStateUpdated(fn ($set) => $set('archivo', null))
                            ->required(),
                        TextInput::make('titulo')
                            ->label('Título')
                            ->maxLength(250)
                            ->helperText('Opcional: si lo deja vacío se usa el nombre del archivo.'),
                        // el selector acepta solo los formatos del tipo elegido
                        $this->campoArchivo()
                            ->disabled(fn (Get $get) => blank($get('tipo_documento_id')))
                            ->label(fn (Get $get) => $this->etiquetaArchivo(TipoDocumento::find($get('tipo_documento_id'))))
                            ->acceptedFileTypes(fn (Get $get) => TipoDocumento::find($get('tipo_documento_id'))?->mimesPermitidos() ?? []),
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
                Action::make('generar_expediente_publicacion')
                    ->label('Generar Expediente')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->color('success')
                    ->visible(fn () => app(ServicioExpedientePublicacion::class)->puedeGenerar($this->getOwnerRecord(), auth()->user()))
                    ->modalHeading('Generar Expediente')
                    ->modalDescription('Todos los documentos vienen marcados; desmarque los que no desee incluir en el archivo .zip.')
                    ->modalSubmitActionLabel('Generar y descargar')
                    ->modalWidth('2xl')
                    ->fillForm(fn () => ['elementos' => app(ServicioExpedientePublicacion::class)->marcadasPorDefecto($this->getOwnerRecord(), auth()->user())])
                    ->schema(fn () => [
                        CheckboxList::make('elementos')
                            ->label('Documentos')
                            ->options(app(ServicioExpedientePublicacion::class)->opciones($this->getOwnerRecord(), auth()->user())->pluck('etiqueta', 'clave'))
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        try {
                            [$ruta, $nombre] = app(ServicioExpedientePublicacion::class)->generar(
                                $this->getOwnerRecord(), auth()->user(), (array) ($data['elementos'] ?? [])
                            );
                        } catch (DomainException $e) {
                            Notification::make()->title('No se pudo generar el expediente')->body($e->getMessage())->danger()->send();

                            return null;
                        }

                        return app(ServicioDocumentoFijo::class)->entregar($ruta, $nombre, true);
                    }),
                Action::make('generar_fase_preparatoria')
                    ->label('Fase Preparatoria')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->color('gray')
                    ->visible(fn () => app(ServicioExpedientePublicacion::class)->puedeGenerarFasePreparatoria($this->getOwnerRecord(), auth()->user()))
                    ->action(function () {
                        try {
                            [$ruta, $nombre] = app(ServicioExpedientePublicacion::class)->generarFasePreparatoria($this->getOwnerRecord(), auth()->user());
                        } catch (DomainException $e) {
                            Notification::make()->title('No se pudo generar el expediente')->body($e->getMessage())->danger()->send();

                            return null;
                        }

                        return app(ServicioDocumentoFijo::class)->entregar($ruta, $nombre, true);
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
                Action::make('descargar_legalizado')
                    ->label('Descargar legalizado')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Documento $record) => $record->estadoDocumento?->codigo === 'LEGALIZADO'
                        && $servicio->puedeDescargar($record, auth()->user()))
                    ->url(fn (Documento $record) => route('documentos.descargar_legalizado', $record)),
                Action::make('eliminar')
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (Documento $record) => $servicio->puedeAnular($record, auth()->user()))
                    ->modalHeading(fn (Documento $record) => 'Eliminar: '.$record->titulo)
                    ->modalDescription('El documento deja de formar parte del expediente; queda un registro de respaldo.')
                    ->schema([Textarea::make('motivo')->label('Motivo')->rows(2)->required()])
                    ->action(function (array $data, Documento $record) use ($servicio) {
                        $this->ejecutar(fn () => $servicio->anularDocumento($record, auth()->user(), (string) $data['motivo']), 'Documento eliminado');
                        $this->recargarPagina();
                    }),
                Action::make('nueva_version')
                    // El analista "sube" su documento corregido; la unidad requirente carga una "nueva versión".
                    ->label(fn () => $servicio->esAnalistaAsignado($this->getOwnerRecord(), auth()->user()) ? 'Subir documento' : 'Nueva versión')
                    ->icon('heroicon-o-document-plus')
                    ->color('warning')
                    // la unidad carga y corrige sus documentos desde "Enviar a revision" / "Reenviar" (salvo "Otros documentos")
                    ->visible(fn (Documento $record) => $servicio->puedeAgregarVersion($record, auth()->user())
                        && ! (auth()->user()->tieneRol('UNIDAD_REQUIRENTE') && $record->tipoDocumento->requiere_revision
                            && $record->tipoDocumento->codigo !== ServicioDocumento::TIPO_OTROS && $record->proceso->esEditable()))
                    ->schema(fn (Documento $record) => [
                        $this->campoArchivo()
                            ->label($this->etiquetaArchivo($record->tipoDocumento))
                            ->acceptedFileTypes($record->tipoDocumento->mimesPermitidos()),
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

    private function etiquetaArchivo(?TipoDocumento $tipo): string
    {
        return $tipo ? "Archivo ({$tipo->formatosLegibles()})" : 'Archivo (seleccione primero el tipo de documento)';
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
