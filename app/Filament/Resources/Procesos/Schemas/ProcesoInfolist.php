<?php

namespace App\Filament\Resources\Procesos\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProcesoInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Proceso')
                ->columns(3)
                ->schema([
                    TextEntry::make('codigo')->label('Código')->placeholder('Sin asignar'),
                    TextEntry::make('estadoMacro.nombre')->label('Fase')->badge(),
                    TextEntry::make('estadoProceso.nombre')->label('Estado')->badge(),
                    TextEntry::make('tipoProceso.nombre')->label('Tipo'),
                    TextEntry::make('anio')->label('Año'),
                    TextEntry::make('ciclo')->label('Ciclo'),
                    TextEntry::make('codigo_cpc')->label('Código CPC')->placeholder('-'),
                    TextEntry::make('numero_pedido')->label('N.º de pedido'),
                    TextEntry::make('creador.name')->label('Creado por'),
                    TextEntry::make('objeto')->label('Objeto')->columnSpanFull(),
                    TextEntry::make('observacion')->label('Observación')->placeholder('-')->columnSpanFull(),
                ]),
            Section::make('Ítems')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('numero_linea')->label('Línea'),
                            TextEntry::make('cum')->label('CUM'),
                            TextEntry::make('cantidad')->label('Cantidad')->numeric(),
                            TextEntry::make('descripcion')->label('Descripción'),
                        ]),
                ]),
            Section::make('Historial de estados')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('historial')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
                            TextEntry::make('transicion.nombre')->label('Acción'),
                            TextEntry::make('estadoDestino.nombre')->label('Estado resultante')->badge(),
                            TextEntry::make('usuario.name')->label('Usuario'),
                            TextEntry::make('comentario')->label('Comentario')->placeholder('-')->columnSpanFull(),
                        ]),
                ]),
        ]);
    }
}
