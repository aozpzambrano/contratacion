<?php

namespace App\Filament\Resources\Procesos\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Historial de estados del proceso (solo lectura); va despues de Documentos. */
class HistorialRelationManager extends RelationManager
{
    protected static string $relationship = 'historial';

    protected static ?string $title = 'Historial de estados';

    protected static bool $isLazy = false;

    /** La Comision Tecnica no ve el historial de estados. */
    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        $u = auth()->user();

        return ! ($u && $u->tieneRol('COMISION_TECNICA') && ! $u->tieneRol('COMPRAS_JEFE') && ! $u->tieneRol('COMPRAS_ANALISTA') && ! $u->tieneRol('ADMIN'));
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['transicion', 'estadoDestino', 'usuario']))
            ->defaultSort('id')
            ->paginated(false)
            ->emptyStateHeading('Sin movimientos registrados')
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
                TextColumn::make('transicion.nombre')->label('Acción'),
                TextColumn::make('estadoDestino.nombre')->label('Estado resultante')->badge(),
                TextColumn::make('usuario.name')->label('Usuario'),
                TextColumn::make('comentario')->label('Comentario')->placeholder('-')->wrap(),
            ]);
    }
}
