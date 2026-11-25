<?php

namespace App\Filament\Resources\TiposDocumento\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TiposDocumentoTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('fase'))
            ->columns([
                TextColumn::make('nombre')->label('Nombre')->searchable()->sortable()->wrap(),
                TextColumn::make('codigo')->label('Código')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('fase.nombre')->label('Fase')->placeholder('Cualquiera'),
                TextColumn::make('extensiones_permitidas')->label('Formatos')->formatStateUsing(fn ($state) => strtoupper(str_replace(',', ', ', $state))),
                TextColumn::make('tamano_maximo_mb')->label('MB máx.'),
                IconColumn::make('requiere_revision')->label('Revisión')->boolean(),
                IconColumn::make('requiere_legalizacion')->label('Legaliz.')->boolean(),
                IconColumn::make('permite_multiples')->label('Varios')->boolean(),
                IconColumn::make('editable_tras_devolucion')->label('Editable tras devolución')->boolean(),
                IconColumn::make('activo')->label('Activo')->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('nombre');
    }
}
