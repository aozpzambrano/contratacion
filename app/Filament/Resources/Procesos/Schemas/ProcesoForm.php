<?php

namespace App\Filament\Resources\Procesos\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProcesoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del proceso')
                ->columns(2)
                ->schema([
                    Select::make('tipo_proceso_id')
                        ->label('Tipo de proceso')
                        ->relationship('tipoProceso', 'nombre', fn ($query) => $query->where('activo', true))
                        ->default(fn () => \App\Models\TipoProceso::where('activo', true)->orderBy('id')->value('id'))
                        ->required(),
                    TextInput::make('anio')
                        ->label('Año')
                        ->numeric()
                        ->minValue(2000)
                        ->maxValue(2100)
                        ->default(now()->year)
                        ->required(),
                    Textarea::make('descripcion_producto')
                        ->label('Descripción del producto')
                        ->required()
                        ->columnSpanFull(),
                    TextInput::make('codigo_cpc')
                        ->label('Código CPC')
                        ->maxLength(20),
                    TextInput::make('numero_pedido')
                        ->label('N.º de pedido')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                    Textarea::make('observacion')
                        ->label('Observación')
                        ->columnSpanFull(),
                ]),
            Section::make('Ítems')
                ->description('Medicamentos o bienes del proceso.')
                ->schema([
                    Repeater::make('items')
                        ->label('Ítems')
                        ->relationship('items')
                        ->orderColumn('numero_linea')
                        ->minItems(1)
                        ->columns(3)
                        ->addActionLabel('Agregar ítem')
                        ->schema([
                            TextInput::make('cum')
                                ->label('CUM')
                                ->required()
                                ->maxLength(30),
                            TextInput::make('cantidad')
                                ->label('Cantidad')
                                ->numeric()
                                ->minValue(1)
                                ->required(),
                            Textarea::make('detalle')
                                ->label('Detalle')
                                ->required()
                                ->rows(2)
                                ->columnSpan(3),
                        ]),
                ]),
        ]);
    }
}
