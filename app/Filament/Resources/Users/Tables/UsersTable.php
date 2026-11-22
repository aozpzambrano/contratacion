<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('email')->label('Correo')->searchable(),
                TextColumn::make('cedula')->label('Cédula')->searchable(),
                TextColumn::make('cargo')->label('Cargo')->searchable()->toggleable(),
                TextColumn::make('unidad')->label('Unidad')->searchable()->toggleable(),
                TextColumn::make('roles.nombre')->label('Roles')->badge(),
                IconColumn::make('activo')->label('Activo')->boolean(),
                TextColumn::make('created_at')->label('Creado')->dateTime('d/m/Y H:i')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('activo')->label('Activo'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }
}
