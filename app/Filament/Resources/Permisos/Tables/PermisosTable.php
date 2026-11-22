<?php

namespace App\Filament\Resources\Permisos\Tables;

use App\Models\Permiso;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PermisosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')->label('Código')->searchable()->sortable(),
                TextColumn::make('nombre')->label('Nombre')->searchable(),
                TextColumn::make('modulo')->label('Módulo')->badge()->sortable(),
                IconColumn::make('requiere_asignacion')->label('Exige asignación')->boolean(),
                TextColumn::make('roles.codigo')->label('Roles')->badge(),
            ])
            ->filters([
                SelectFilter::make('modulo')->label('Módulo')
                    ->options(fn () => Permiso::query()->distinct()->orderBy('modulo')->pluck('modulo', 'modulo')->all()),
            ])
            ->defaultSort('codigo')
            ->paginated([25, 50, 100]);
    }
}
