<?php

namespace App\Filament\Resources\Procesos\Schemas;

use App\Services\ServicioOferta;
use App\Services\ServicioRemisionOfertas;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProcesoInfolist
{
    /** La Comision Tecnica solo ve Proceso, Items, Ofertas y Documentos. */
    private static function esComision(): bool
    {
        $u = auth()->user();

        return $u !== null && $u->tieneRol('COMISION_TECNICA') && ! $u->tieneRol('COMPRAS_JEFE') && ! $u->tieneRol('COMPRAS_ANALISTA') && ! $u->tieneRol('ADMIN');
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // Aviso informativo para Compras: designacion segun el mayor monto total de las ofertas recibidas.
            Section::make('Designación sugerida')
                ->columnSpanFull()
                ->icon('heroicon-o-information-circle')
                ->iconColor('info')
                ->visible(fn ($record) => auth()->user() && app(ServicioRemisionOfertas::class)->puedeVerAviso($record, auth()->user())
                    && app(ServicioRemisionOfertas::class)->designacionSugerida($record) !== null)
                ->schema([
                    TextEntry::make('aviso_designacion')
                        ->hiddenLabel()
                        ->state(function ($record) {
                            $d = app(ServicioRemisionOfertas::class)->designacionSugerida($record);

                            return 'Según el mayor monto total entre las ofertas recibidas (USD '
                                .number_format($d['monto'], 2, ',', '.').') se deberá conformar: '.$d['nombre'].'.';
                        }),
                ]),
            Grid::make(['default' => 1, 'lg' => 2])
                ->columnSpanFull()
                ->schema([
                Section::make('Proceso')
                    ->columns(2)
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
                            ->columns(2)
                            ->schema([
                                TextEntry::make('numero_linea')->label('Línea'),
                                TextEntry::make('cum')->label('CUM'),
                                TextEntry::make('cantidad')->label('Cantidad')->numeric(),
                                TextEntry::make('descripcion')->label('Descripción'),
                            ]),
                    ]),
                ]),
            Section::make('Solicitud de publicación')
                ->columnSpanFull()
                ->columns(2)
                ->visible(fn ($record) => ! self::esComision() && $record->solicitudesPublicacion()->exists())
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
                ->columnSpanFull()
                ->collapsible()
                ->visible(fn ($record) => ! self::esComision() && $record->publicaciones()->exists())
                ->schema([
                    ViewEntry::make('publicaciones_tabla')
                        ->hiddenLabel()
                        ->state(fn ($record) => $record->publicaciones()->get()->map(fn ($p) => [
                            'secuencia' => $p->secuencia,
                            'codigo_nc' => $p->codigo_nc,
                            'fecha_publicacion' => $p->fecha_publicacion,
                            'fecha_limite' => $p->fecha_limite,
                            'enlace' => $p->enlace,
                        ])->all())
                        ->view('filament.infolists.publicaciones-tabla')
                        ->columnSpanFull(),
                ]),
            Section::make('Ofertas')
                ->columnSpanFull()
                ->collapsible()
                // aparece al registrar las ofertas de una publicacion, incluso si fueron 0
                ->visible(fn ($record) => $record->ofertas()->exists() || $record->publicaciones()->whereNotNull('ofertas_registradas_en')->exists())
                ->schema([
                    // las ofertas se agrupan por numero de publicacion; una publicacion sin ofertas muestra "0 ofertas"
                    RepeatableEntry::make('ofertas_por_publicacion')
                        ->hiddenLabel()
                        ->state(function ($record) {
                            $porPublicacion = app(ServicioOferta::class)->resumen($record)
                                ->map(fn ($o) => $o + [
                                    'total_texto' => '$ '.number_format($o['total'], 2, '.', ','),
                                    'detalle_tabla' => $o['items'],
                                    'zip' => $o['archivos'] === [] ? null : ['url' => route('ofertas.descargar_zip', $o['id']), 'archivos' => count($o['archivos'])],
                                ])
                                ->groupBy('publicacion');

                            // publicaciones con ofertas registradas (aunque sean 0) o con alguna oferta
                            return $record->publicaciones()->get()
                                ->filter(fn ($p) => $p->ofertas_registradas_en !== null || $porPublicacion->has((int) $p->secuencia))
                                ->map(function ($p) use ($porPublicacion) {
                                    $ofertas = $porPublicacion->get((int) $p->secuencia, collect());

                                    return [
                                        'titulo' => 'Publicación N.° '.$p->secuencia.' — '.$ofertas->count().($ofertas->count() === 1 ? ' oferta' : ' ofertas'),
                                        'ofertas' => $ofertas->values()->all(),
                                    ];
                                })->values()->all();
                        })
                        ->schema([
                            TextEntry::make('titulo')->hiddenLabel()->weight('bold'),
                            RepeatableEntry::make('ofertas')
                                ->hiddenLabel()
                                ->columns(2)
                                ->schema([
                                    TextEntry::make('identificacion')->label('RUC / Cédula'),
                                    TextEntry::make('razon_social')->label('Razón Social'),
                                    ViewEntry::make('detalle_tabla')->hiddenLabel()->view('filament.infolists.oferta-detalle')->columnSpanFull(),
                                    TextEntry::make('total_texto')->label('Total del proceso')->columnSpanFull(),
                                    ViewEntry::make('zip')->hiddenLabel()->view('filament.infolists.oferta-zip')->columnSpanFull(),
                                ]),
                        ]),
                ]),
        ]);
    }
}
