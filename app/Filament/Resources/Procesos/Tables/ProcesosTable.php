<?php

namespace App\Filament\Resources\Procesos\Tables;

use App\Models\EstadoMacro;
use App\Models\EstadoProceso;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProcesosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('N.º')->sortable(),
                TextColumn::make('codigo')->label('Código')->searchable()->placeholder('Sin asignar'),
                TextColumn::make('descripcion_producto')->label('Descripción del producto')->limit(60)->searchable()->wrap(),
                TextColumn::make('anio')->label('Año')->sortable(),
                TextColumn::make('estadoMacro.nombre')->label('Fase')->badge(),
                TextColumn::make('estadoProceso.nombre')->label('Estado')->badge(),
                TextColumn::make('ciclo')->label('Ciclo')->toggleable(),
                TextColumn::make('creador.name')->label('Creado por')->toggleable(),
                TextColumn::make('created_at')->label('Creado')->dateTime('d/m/Y H:i')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('estado_macro_id')->label('Fase')
                    ->options(fn () => EstadoMacro::orderBy('orden')->pluck('nombre', 'id')->all()),
                SelectFilter::make('estado_proceso_id')->label('Estado')
                    ->options(fn () => EstadoProceso::orderBy('orden')->pluck('nombre', 'id')->all()),
                SelectFilter::make('anio')->label('Año')
                    ->options(fn () => \App\Models\Proceso::query()->distinct()->orderByDesc('anio')->pluck('anio', 'anio')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }
}
