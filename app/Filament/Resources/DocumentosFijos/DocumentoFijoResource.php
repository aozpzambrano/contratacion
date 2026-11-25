<?php

namespace App\Filament\Resources\DocumentosFijos;

use App\Filament\Resources\DocumentosFijos\Pages\EditDocumentoFijo;
use App\Filament\Resources\DocumentosFijos\Pages\ListDocumentosFijos;
use App\Filament\Resources\DocumentosFijos\RelationManagers\VersionesRelationManager;
use App\Models\DocumentoFijo;
use App\Services\ServicioDocumento;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Documentos fijos del proceso (reglamento, proyecto de orden de compra, anexos): el administrador
 * los reemplaza aqui; cada reemplazo conserva la version anterior como respaldo.
 */
class DocumentoFijoResource extends Resource
{
    protected static ?string $model = DocumentoFijo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'documentos-fijos';

    protected static ?string $modelLabel = 'documento fijo';

    protected static ?string $pluralModelLabel = 'documentos fijos del proceso';

    protected static ?string $navigationLabel = 'Documentos fijos del proceso';

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Documento')
                ->columns(2)
                ->schema([
                    TextInput::make('nombre')->label('Nombre')->required()->maxLength(200),
                    TextInput::make('tipo_proceso')->label('Tipo de proceso')->disabled()->dehydrated(false)
                        ->formatStateUsing(fn (?DocumentoFijo $record) => $record?->tipoProceso?->nombre),
                    Textarea::make('descripcion')->label('Descripción')->rows(2)->columnSpanFull(),
                    Toggle::make('activo')->label('Activo (visible para Compras)')->default(true),
                    TextInput::make('formatos')->label('Formatos permitidos')->disabled()->dehydrated(false)
                        ->formatStateUsing(fn (?DocumentoFijo $record) => $record ? strtoupper(str_replace(',', ', ', $record->extensiones_permitidas)) : null),
                ]),
            Section::make('Reemplazar archivo')
                ->description('El archivo anterior se conserva como respaldo en el historial de versiones.')
                ->schema([
                    FileUpload::make('nuevo_archivo')
                        ->label('Nuevo archivo')
                        ->helperText(fn (?DocumentoFijo $record) => ($record?->es_plantilla
                            ? 'Es una plantilla: debe conservar los marcadores {{objeto_contratacion}} y {{numero_proceso}}. ' : '')
                            .'Formatos: '.strtoupper(implode(', ', $record?->extensiones() ?? [])).'.')
                        ->disk(ServicioDocumento::DISCO)
                        ->directory('temporal')
                        ->visibility('private')
                        ->storeFileNamesIn('nombre_archivo')
                        ->dehydrated(),
                    Textarea::make('comentario_version')->label('Comentario del cambio (opcional)')->rows(2)->dehydrated(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['tipoProceso', 'archivo']))
            ->columns([
                TextColumn::make('tipoProceso.nombre')->label('Tipo de proceso')->wrap(),
                TextColumn::make('nombre')->label('Documento')->searchable()->wrap(),
                IconColumn::make('es_plantilla')->label('Plantilla')->boolean(),
                TextColumn::make('archivo.nombre_original')->label('Archivo vigente')->placeholder('Sin archivo')->wrap(),
                TextColumn::make('updated_at')->label('Actualizado')->dateTime('d/m/Y H:i'),
                IconColumn::make('activo')->label('Activo')->boolean(),
            ])
            ->recordActions([EditAction::make()->label('Editar')])
            ->defaultSort('orden')
            ->paginated(false);
    }

    public static function getRelations(): array
    {
        return [VersionesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentosFijos::route('/'),
            'edit' => EditDocumentoFijo::route('/{record}/edit'),
        ];
    }
}
