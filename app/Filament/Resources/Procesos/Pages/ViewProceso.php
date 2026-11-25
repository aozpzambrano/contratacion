<?php

namespace App\Filament\Resources\Procesos\Pages;

use App\Filament\Resources\Procesos\ProcesoResource;
use App\Models\Proceso;
use App\Models\User;
use App\Services\ServicioDevolucionSinOfertas;
use App\Services\ServicioDocumento;
use App\Services\ServicioDocumentoFijo;
use App\Services\ServicioLegalizacion;
use App\Services\ServicioOferta;
use App\Services\ServicioPublicacion;
use App\Services\ServicioProceso;
use App\Services\ServicioRemisionDocumentacion;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class ViewProceso extends ViewRecord
{
    protected static string $resource = ProcesoResource::class;

    protected function getHeaderActions(): array
    {
        $servicio = app(ServicioProceso::class);
        $usuario = auth()->user();
        $proceso = $this->getRecord();
        $acciones = [];

        // Transiciones del flujo disponibles para este usuario en el estado actual
        foreach ($servicio->transicionesDisponibles($proceso, $usuario) as $transicion) {
            // "Remitir documentos legalizados" tiene su propio formulario (PDF firmados, memorando y publicaciones)
            if (in_array($transicion->codigo, ServicioLegalizacion::TRANSICIONES, true)) {
                $acciones[] = $this->accionRemitirLegalizados($proceso, $usuario);

                continue;
            }

            // Decisiones del flujo, solo despues de registrar las ofertas de la ultima publicacion (las calcula la BD):
            // "Remitir ofertas", "Publicar" de nuevo o devolver el expediente (formulario propio, ver mas abajo).
            if ($transicion->codigo === 'remitir_ofertas' && ! app(ServicioOferta::class)->puedeRemitir($proceso)) {
                continue;
            }

            // La devolucion sin ofertas (desde Publicado) incluye la reapertura y sus dos PDF: tiene su propia accion.
            if ($transicion->codigo === 'devolver_expediente' && $proceso->estadoProceso?->codigo === 'PUBLICADO') {
                continue;
            }

            // La unidad carga Estudio de Mercado y Certificacion POA (formulario propio)
            if ($transicion->codigo === 'remitir_documentacion') {
                $acciones[] = $this->accionRemitirDocumentacion($proceso, $usuario);

                continue;
            }

            // "Publicar" pide los datos de la publicacion (necesidad, link y fechas)

            if (in_array($transicion->codigo, ['publicar', 'republicar'], true)) {
                if (! app(ServicioPublicacion::class)->puedePublicar($proceso, $usuario)) {
                    continue;
                }
                $acciones[] = $this->accionPublicar($proceso, $usuario);

                continue;
            }

            $acciones[] = Action::make('transicion_'.$transicion->codigo)
                ->label($transicion->nombre)
                ->color($transicion->requiere_comentario ? 'warning' : 'primary')
                ->modalHeading($transicion->nombre)
                ->modalSubmitActionLabel('Confirmar')
                ->schema($this->camposDeTransicion($transicion, $proceso))
                ->action(function (array $data) use ($servicio, $proceso, $transicion, $usuario) {
                    $this->ejecutar(fn () => $servicio->aplicarTransicion(
                        $proceso, $transicion, $usuario, $data['comentario'] ?? null, ['documentos' => $data['documentos'] ?? null]
                    ),
                        'Estado actualizado: '.$transicion->estadoDestino->nombre);
                });
        }

        // Compras genera el codigo antes de publicar
        if ($servicio->puedeGenerarCodigo($proceso, $usuario)) {
            $acciones[] = Action::make('generar_codigo')
                ->label('Generar código del proceso')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Se asignará el siguiente número disponible. El código no podrá modificarse.')
                ->action(function () use ($servicio, $proceso, $usuario) {
                    $this->ejecutar(fn () => $servicio->generarCodigo($proceso, $usuario), 'Código del proceso generado');
                });
        }

        // El jefe de Compras asigna al analista responsable
        if ($servicio->puedeAsignarAnalista($proceso, $usuario)) {
            $acciones[] = Action::make('asignar_analista')
                ->label('Asignar analista')
                ->color('gray')
                ->schema([
                    Select::make('analista_id')
                        ->label('Analista de Compras')
                        ->options(fn () => $servicio->analistasDisponibles())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data) use ($servicio, $proceso, $usuario) {
                    $this->ejecutar(fn () => $servicio->asignarAnalista(
                        $proceso, User::findOrFail($data['analista_id']), $usuario
                    ), 'Analista asignado');
                });
        }

        if (app(ServicioOferta::class)->puedeRegistrar($proceso, $usuario)) {
            $acciones[] = $this->accionOfertas($proceso, $usuario);
        }

        if (app(ServicioDevolucionSinOfertas::class)->puedeDevolver($proceso, $usuario)) {
            $acciones[] = $this->accionDevolverSinOfertas($proceso, $usuario);
        }

        // Documentos fijos del tipo de proceso (reglamento, orden de compra, anexos) para Compras
        $fijos = app(ServicioDocumentoFijo::class);
        if ($fijos->puedeVer($proceso, $usuario)) {
            $descargas = [];
            foreach ($fijos->disponibles($proceso) as $fijo) {
                $descargas[] = Action::make('fijo_'.$fijo->id)
                    ->label($fijo->nombre.($fijo->es_plantilla ? ' (Word, completado)' : ''))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn () => $fijos->descargar($fijo, $proceso, $usuario));
            }
            if ($descargas !== []) {
                $acciones[] = ActionGroup::make($descargas)
                    ->label('Documentos fijos')
                    ->icon('heroicon-o-folder-open')
                    ->button()
                    ->color('gray');
            }
        }

        $acciones[] = EditAction::make();

        return $acciones;
    }

    private function filaOferta(Proceso $proceso): array
    {
        return [
            'identificacion' => null,
            'razon_social' => null,
            'precios' => $proceso->items->map(fn ($i) => [
                'item_id' => $i->id, 'linea' => $i->numero_linea, 'descripcion' => $i->descripcion, 'cantidad' => (int) $i->cantidad, 'precio' => null,
            ])->values()->all(),
        ];
    }

    private static function dinero(float $valor): string
    {
        return '$ '.number_format($valor, 2, '.', ',');
    }

    /** Ofertas de la ultima publicacion: numero, proveedor y precio unitario por cada item (el total se calcula). */
    private function accionOfertas(Proceso $proceso, User $usuario): Action
    {
        $proceso->loadMissing('items');
        $max = ServicioOferta::MAX_OFERTAS;

        return Action::make('ofertas')
            ->label('Ofertas')
            ->icon('heroicon-o-currency-dollar')
            ->color('success')
            ->modalHeading('Registrar ofertas')
            ->modalDescription('Cada oferta cubre todos los ítems. El total de cada ítem es cantidad × precio unitario.')
            ->modalSubmitActionLabel('Registrar ofertas')
            ->modalWidth('5xl')
            ->fillForm(fn () => ['numero_ofertas' => 1, 'ofertas' => [$this->filaOferta($proceso)]])
            ->schema([
                Select::make('numero_ofertas')
                    ->label('Número de ofertas recibidas')
                    ->options(array_combine(range(0, $max), range(0, $max)))
                    ->live()
                    ->afterStateUpdated(function ($state, Get $get, Set $set) use ($proceso) {
                        $actuales = array_values((array) $get('ofertas'));
                        $filas = [];
                        for ($i = 0; $i < (int) $state; $i++) {
                            $filas[] = $actuales[$i] ?? $this->filaOferta($proceso);
                        }
                        $set('ofertas', $filas);
                    })
                    ->required(),
                Repeater::make('ofertas')
                    ->hiddenLabel()
                    ->addable(false)->deletable(false)->reorderable(false)
                    ->itemLabel(fn (array $state) => filled($state['razon_social'] ?? null) ? $state['razon_social'] : 'Oferta')
                    ->columns(2)
                    ->schema([
                        TextInput::make('identificacion')->label('RUC / Cédula')->maxLength(13)->required(),
                        TextInput::make('razon_social')->label('Razón Social')->maxLength(250)->required(),
                        Repeater::make('precios')
                            ->label('Precio unitario por ítem')
                            ->addable(false)->deletable(false)->reorderable(false)
                            ->columnSpanFull()
                            ->columns(3)
                            ->schema([
                                Hidden::make('item_id'), Hidden::make('linea'), Hidden::make('descripcion'), Hidden::make('cantidad'),
                                Text::make(fn (Get $get) => '#'.$get('linea').' — '.$get('descripcion').' (cantidad: '.number_format((int) $get('cantidad')).')')->columnSpan(1),
                                TextInput::make('precio')->label('Precio unitario')->numeric()->minValue(0)->prefix('$')->live(onBlur: true)->required(),
                                Text::make(fn (Get $get) => 'Total del ítem: '.self::dinero((float) $get('cantidad') * (float) $get('precio'))),
                            ]),
                        Text::make(fn (Get $get) => 'Total del proceso: '.self::dinero(
                            collect((array) $get('precios'))->sum(fn ($p) => (float) ($p['cantidad'] ?? 0) * (float) ($p['precio'] ?? 0))
                        ))->columnSpanFull(),
                    ]),
            ])
            ->action(function (array $data) use ($proceso, $usuario) {
                $ofertas = collect(array_values((array) $data['ofertas']))->map(fn ($o) => [
                    'identificacion' => (string) ($o['identificacion'] ?? ''),
                    'razon_social' => (string) ($o['razon_social'] ?? ''),
                    'precios' => collect((array) ($o['precios'] ?? []))->mapWithKeys(fn ($p) => [(int) $p['item_id'] => $p['precio'] ?? null])->all(),
                ])->all();

                $this->ejecutar(fn () => app(ServicioOferta::class)->registrar($proceso, $usuario, $ofertas), 'Ofertas registradas');
            });
    }

    /** Datos de la publicacion: necesidad de contratacion (texto), link y fechas (solo fecha, sin hora). */
    private function accionPublicar(Proceso $proceso, User $usuario): Action
    {
        $servicio = app(ServicioPublicacion::class);
        $hechas = $proceso->publicaciones()->count();
        $pedidas = $servicio->publicacionesSolicitadas($proceso);

        return Action::make('transicion_publicar')
            ->label('Publicar')
            ->icon('heroicon-o-megaphone')
            ->modalHeading('Publicar')
            ->modalDescription('Publicación '.($hechas + 1).($pedidas > 0 ? " de {$pedidas} solicitada(s) por la unidad requirente." : '.'))
            ->modalSubmitActionLabel('Registrar publicación')
            ->modalWidth('2xl')
            ->schema([
                TextInput::make('necesidad')
                    ->label('Necesidad de Contratación Publicación')
                    ->maxLength(ServicioPublicacion::MAX_NECESIDAD)
                    ->required(),
                TextInput::make('enlace')
                    ->label('Link de Publicación')
                    ->url()
                    ->placeholder('https://')
                    ->required(),
                DateTimePicker::make('fecha_publicacion')
                    ->label('Fecha de Publicación')
                    ->native(false)
                    ->displayFormat('d/m/Y H:i')->seconds(false)
                    ->live()
                    ->required(),
                DateTimePicker::make('fecha_limite')
                    ->label('Fecha Límite')
                    ->helperText('Fecha y hora; debe ser igual o posterior a la Fecha de Publicación.')
                    ->native(false)
                    ->displayFormat('d/m/Y H:i')->seconds(false)
                    ->afterOrEqual('fecha_publicacion')
                    ->required(),
            ])
            ->action(function (array $data) use ($servicio, $proceso, $usuario) {
                $this->ejecutar(fn () => $servicio->registrar(
                    $proceso, $usuario, (string) $data['necesidad'], (string) $data['enlace'],
                    (string) $data['fecha_publicacion'], (string) $data['fecha_limite'],
                ), 'Publicación registrada');
            });
    }

    /**
     * Un solo formulario: PDF legalizados (firmados electronicamente) de los documentos que lo exigen,
     * memorando de solicitud de publicacion, su numero y las horas de cada publicacion.
     */
    private function accionRemitirLegalizados(Proceso $proceso, User $usuario): Action
    {
        $pendientes = app(ServicioDocumento::class)->documentosPorLegalizar($proceso);

        $pdf = fn (string $nombre, string $etiqueta, ?string $guardaNombre = null) => FileUpload::make($nombre)
            ->label($etiqueta)
            ->disk(ServicioDocumento::DISCO)
            ->directory('temporal')
            ->visibility('private')
            ->acceptedFileTypes(['application/pdf'])
            ->storeFileNamesIn($guardaNombre ?? 'nombre_'.$nombre)
            ->required();

        $campos = [];
        foreach ($pendientes as $documento) {
            $campos[] = $pdf("legalizado_{$documento->id}", "{$documento->titulo} legalizado (PDF firmado electrónicamente)");
        }
        $campos[] = $pdf('memorando', 'Memorando de solicitud de publicación (PDF)');
        // Fichas tecnicas, certificados, etc. (varios archivos por tipo); solo los que aun no se han cargado
        $adicionales = app(ServicioLegalizacion::class)->tiposAdicionalesPendientes($proceso);
        foreach ($adicionales as $tipo) {
            $campos[] = FileUpload::make("adicional_{$tipo->id}")
                ->label($tipo->nombre.' (PDF, puede adjuntar varios)')
                ->disk(ServicioDocumento::DISCO)
                ->directory('temporal')
                ->visibility('private')
                ->acceptedFileTypes(['application/pdf'])
                ->multiple()
                ->storeFileNamesIn("nombre_adicional_{$tipo->id}")
                ->required();
        }
        // Distributivo: uno o varios archivos PDF, Word o Excel (opcional)
        $campos[] = FileUpload::make('distributivo')
            ->label('Distributivo (PDF, Word o Excel; puede adjuntar varios)')
            ->disk(ServicioDocumento::DISCO)
            ->directory('temporal')
            ->visibility('private')
            ->acceptedFileTypes([
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->multiple()
            ->storeFileNamesIn('nombre_distributivo');
        $campos[] = TextInput::make('numero_memorando')
            ->label('Número del memorando')
            ->helperText('Ingresar como consta en el Memorando')
            ->maxLength(60)
            ->required();
        $campos[] = Select::make('numero_publicaciones')
            ->label('Número de publicaciones solicitadas')
            ->helperText('Cada publicación puede durar un tiempo distinto. Se indica en horas: 24 horas = 1 día, 48 horas = 2 días')
            ->options(array_combine(range(1, ServicioLegalizacion::MAX_PUBLICACIONES), range(1, ServicioLegalizacion::MAX_PUBLICACIONES)))
            ->default(1)
            ->live()
            ->required();
        for ($n = 1; $n <= ServicioLegalizacion::MAX_PUBLICACIONES; $n++) {
            $campos[] = TextInput::make("horas_{$n}")
                ->label("Publicación {$n}: duración")
                ->numeric()->integer()
                ->minValue(1)->maxValue(ServicioLegalizacion::MAX_HORAS)
                ->suffix('horas')
                ->visible(fn (Get $get) => (int) $get('numero_publicaciones') >= $n)
                ->required(fn (Get $get) => (int) $get('numero_publicaciones') >= $n);
        }

        return Action::make('transicion_remitir_legalizados')
            ->label('Remitir documentos legalizados')
            ->icon('heroicon-o-paper-airplane')
            ->modalHeading('Remitir documentos legalizados')
            ->modalDescription('Al confirmar, la documentación legalizada pasa al área para su publicación.')
            ->modalSubmitActionLabel('Remitir')
            ->modalWidth('3xl')
            ->schema($campos)
            ->action(function (array $data) use ($proceso, $usuario, $pendientes, $adicionales) {
                $archivo = fn (string $campo): array => [$this->rutaSubida($data[$campo]), $this->rutaSubida($data['nombre_'.$campo] ?? $data[$campo])];

                $legalizados = [];
                foreach ($pendientes as $documento) {
                    $legalizados[$documento->id] = $archivo("legalizado_{$documento->id}");
                }
                $extras = [];
                foreach ($adicionales as $tipo) {
                    $nombres = (array) ($data["nombre_adicional_{$tipo->id}"] ?? []);
                    foreach ((array) ($data["adicional_{$tipo->id}"] ?? []) as $ruta) {
                        $extras[$tipo->id][] = [(string) $ruta, (string) ($nombres[$ruta] ?? basename((string) $ruta))];
                    }
                }
                $distributivos = [];
                $nombresDistributivo = (array) ($data['nombre_distributivo'] ?? []);
                foreach ((array) ($data['distributivo'] ?? []) as $ruta) {
                    $distributivos[] = [(string) $ruta, (string) ($nombresDistributivo[$ruta] ?? basename((string) $ruta))];
                }

                $horas = [];
                for ($n = 1; $n <= (int) $data['numero_publicaciones']; $n++) {
                    $horas[] = $data["horas_{$n}"] ?? null;
                }

                $this->ejecutar(fn () => app(ServicioLegalizacion::class)->remitir(
                    $proceso, $usuario, $legalizados, $archivo('memorando'), (string) $data['numero_memorando'], $horas, $extras, $distributivos
                ), 'Documentos legalizados remitidos a Compras');
            });
    }

    private function campoPdf(string $nombre, string $etiqueta): FileUpload
    {
        return FileUpload::make($nombre)
            ->label($etiqueta)
            ->disk(ServicioDocumento::DISCO)
            ->directory('temporal')
            ->visibility('private')
            ->acceptedFileTypes(['application/pdf'])
            ->storeFileNamesIn('nombre_'.$nombre)
            ->required();
    }

    /** La unidad requirente remite a Compras el Estudio de Mercado y la Certificacion POA (PDF legalizados). */
    private function accionRemitirDocumentacion(Proceso $proceso, User $usuario): Action
    {
        return Action::make('transicion_remitir_documentacion')
            ->label('Remitir documentación')
            ->icon('heroicon-o-paper-airplane')
            ->modalHeading('Remitir Estudio de Mercado y Certificación POA')
            ->modalDescription('Cargue un único PDF legalizado por cada documento. Al confirmar, pasan a Compras para la comisión técnica.')
            ->modalSubmitActionLabel('Remitir')
            ->schema([
                $this->campoPdf('estudio', 'Estudio de Mercado (PDF legalizado)'),
                $this->campoPdf('poa', 'Certificación POA (PDF legalizado)'),
            ])
            ->action(function (array $data) use ($proceso, $usuario) {
                $archivo = fn (string $campo): array => [$this->rutaSubida($data[$campo]), $this->rutaSubida($data['nombre_'.$campo] ?? $data[$campo])];

                $this->ejecutar(fn () => app(ServicioRemisionDocumentacion::class)->remitir(
                    $proceso, $usuario, $archivo('estudio'), $archivo('poa')
                ), 'Documentación remitida a Compras');
            });
    }

    /** Publicaciones agotadas y 0 ofertas: memorando e informe de devolucion; el proceso queda en Para Re-publicar. */
    private function accionDevolverSinOfertas(Proceso $proceso, User $usuario): Action
    {
        return Action::make('devolver_sin_ofertas')
            ->label('Devolver expediente (sin ofertas)')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->modalHeading('Devolver el expediente por falta de ofertas')
            ->modalDescription('Cargue los PDF legalizados. Se cierra el ciclo actual y la unidad requirente deberá remitir nuevamente memorando, horas y número de publicaciones.')
            ->modalSubmitActionLabel('Devolver')
            ->schema([
                $this->campoPdf('memorando', 'Memorando de devolución del expediente (PDF legalizado)'),
                $this->campoPdf('informe', 'Informe de devolución por falta de ofertas (PDF legalizado)'),
            ])
            ->action(function (array $data) use ($proceso, $usuario) {
                $archivo = fn (string $campo): array => [$this->rutaSubida($data[$campo]), $this->rutaSubida($data['nombre_'.$campo] ?? $data[$campo])];

                $this->ejecutar(fn () => app(ServicioDevolucionSinOfertas::class)->devolver(
                    $proceso, $usuario, $archivo('memorando'), $archivo('informe')
                ), 'Expediente devuelto: el proceso queda en Para Re-publicar');
            });
    }

    private function rutaSubida(mixed $valor): string
    {
        return is_array($valor) ? (string) array_values($valor)[0] : (string) $valor;
    }

    /** Campos del formulario de una transicion: comentario y, al remitir observaciones, los documentos a corregir. */
    private function camposDeTransicion($transicion, $proceso): array
    {
        $campos = [
            Textarea::make('comentario')
                ->label($transicion->requiere_comentario ? 'Comentario (obligatorio)' : 'Comentario (opcional)')
                ->required($transicion->requiere_comentario),
        ];

        if ($transicion->codigo === 'solicitar_correcciones') {
            $documentos = app(ServicioDocumento::class)->documentosEnRevision($proceso)->pluck('titulo', 'id');
            array_unshift($campos, CheckboxList::make('documentos')
                ->label('Documentos que la unidad debe corregir')
                ->helperText('Marque solo los que tienen observaciones: la unidad deberá cargar una nueva versión de cada uno. Los no marcados se consideran correctos.')
                ->options($documentos)
                ->default($documentos->keys()->all())
                ->required());
        }

        return $campos;
    }

    /** Ejecuta una accion del servicio y avisa del resultado; el error de negocio se muestra tal cual. */
    private function ejecutar(callable $accion, string $mensajeExito): void
    {
        try {
            $accion();
        } catch (DomainException $e) {
            Notification::make()->title('No se pudo completar la acción')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($mensajeExito)->success()->send();
        $this->redirect(ProcesoResource::getUrl('view', ['record' => $this->getRecord()]));
    }
}
