<?php

namespace App\Filament\Resources\DocumentosFijos\RelationManagers;

use App\Models\DocumentoFijoVersion;
use App\Services\ServicioDocumentoFijo;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Historial de archivos del documento fijo (respaldo). */
class VersionesRelationManager extends RelationManager
{
    protected static string $relationship = 'versiones';

    protected static ?string $title = 'Historial de versiones (respaldo)';

    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['archivo', 'usuario']))
            ->emptyStateHeading('Aún no se ha cargado ningún archivo')
            ->defaultSort('numero_version', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('numero_version')->label('Versión')->prefix('v'),
                TextColumn::make('archivo.nombre_original')->label('Archivo')->wrap(),
                TextColumn::make('usuario.name')->label('Cargado por'),
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
                TextColumn::make('comentario')->label('Comentario')->placeholder('-')->wrap(),
            ])
            ->recordActions([
                Action::make('descargar')
                    ->label('Descargar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (DocumentoFijoVersion $record) => app(ServicioDocumentoFijo::class)->descargarVersion($record, auth()->user())),
            ]);
    }
}
