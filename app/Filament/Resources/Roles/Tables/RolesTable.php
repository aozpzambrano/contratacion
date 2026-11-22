<?php

namespace App\Filament\Resources\Roles\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')->label('Código')->searchable()->sortable(),
                TextColumn::make('nombre')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('fases.nombre')->label('Fases')->badge(),
                TextColumn::make('permisos_count')->label('Permisos')->counts('permisos'),
                TextColumn::make('usuarios_count')->label('Usuarios')->counts('usuarios'),
                IconColumn::make('es_sistema')->label('Sistema')->boolean(),
                IconColumn::make('activo')->label('Activo')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('codigo');
    }
}
