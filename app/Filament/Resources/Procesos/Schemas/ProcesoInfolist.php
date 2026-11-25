<?php

namespace App\Filament\Resources\Procesos\Schemas;

use App\Services\ServicioOferta;
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
                    TextEntry::make('ciclo')->label('Ciclo'),
                    TextEntry::make('codigo_cpc')->label('Código CPC')->placeholder('-'),
                    TextEntry::make('creador.name')->label('Creado por'),
                    TextEntry::make('objeto_contratacion')->label('Objeto de contratación')->columnSpanFull(),
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
            Section::make('Solicitud de publicación')
                ->columns(2)
                ->visible(fn ($record) => $record->solicitudesPublicacion()->exists())
                ->schema([
                    TextEntry::make('solicitud_memorando')
                        ->label('N.° de memorando')
                        ->state(fn ($record) => $record->solicitudesPublicacion()->first()?->numero_memorando),
                    TextEntry::make('solicitud_estado')
                        ->label('Estado de la solicitud')
                        ->badge()
                        ->state(function ($record) {
                            $s = $record->solicitudesPublicacion()->first();

                            return $s?->estado === 'remitida'
                                ? 'Remitida el '.$s->fecha_remision->setTimezone('America/Guayaquil')->format('d/m/Y H:i')
                                : 'Devuelta a la unidad requirente';
                        }),
                    TextEntry::make('solicitud_publicaciones')
                        ->label('Publicaciones solicitadas (duración en horas)')
                        ->state(fn ($record) => ($s = $record->solicitudesPublicacion()->with('detalle')->first())
                            ? $s->detalle->count().' publicación(es) — '.$s->resumenPublicaciones() : null)
                        ->columnSpanFull(),
                    TextEntry::make('solicitud_motivo')
                        ->label('Motivo de la devolución')
                        ->state(fn ($record) => $record->solicitudesPublicacion()->first()?->motivo_devolucion)
                        ->visible(fn ($record) => $record->solicitudesPublicacion()->first()?->estado === 'devuelta')
                        ->columnSpanFull(),
                ]),
            Section::make('Publicaciones')
                ->visible(fn ($record) => $record->publicaciones()->exists())
                ->schema([
                    RepeatableEntry::make('publicaciones')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('secuencia')->label('N.°'),
                            TextEntry::make('fecha_publicacion')->label('Fecha de Publicación')->dateTime('d/m/Y H:i'),
                            TextEntry::make('fecha_limite')->label('Fecha Límite')->dateTime('d/m/Y H:i'),
                            TextEntry::make('enlace')->label('Link de Publicación')->url(fn ($state) => $state, true)->limit(40),
                            TextEntry::make('codigo_nc')->label('Necesidad de Contratación Publicación')->columnSpanFull(),
                        ]),
                ]),
            Section::make('Ofertas')
                ->visible(fn ($record) => $record->ofertas()->exists())
                ->schema([
                    RepeatableEntry::make('ofertas_resumen')
                        ->hiddenLabel()
                        ->state(fn ($record) => app(ServicioOferta::class)->resumen($record)->map(fn ($o) => $o + [
                            'total_texto' => '$ '.number_format($o['total'], 2, '.', ','),
                            'detalle' => array_map(fn ($i) => "#{$i['linea']} {$i['descripcion']}: {$i['cantidad']} × $ ".number_format($i['precio'], 4, '.', ',').' = $ '.number_format($i['total'], 2, '.', ','), $o['items']),
                        ])->all())
                        ->columns(4)
                        ->schema([
                            TextEntry::make('publicacion')->label('Publicación N.°'),
                            TextEntry::make('identificacion')->label('RUC / Cédula'),
                            TextEntry::make('razon_social')->label('Razón Social'),
                            TextEntry::make('total_texto')->label('Total del proceso'),
                            TextEntry::make('detalle')->label('Precio por ítem')->bulleted()->columnSpanFull(),
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
