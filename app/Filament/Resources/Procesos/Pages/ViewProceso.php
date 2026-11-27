<?php

namespace App\Filament\Resources\Procesos\Pages;

use App\Filament\Resources\Procesos\ProcesoResource;
use App\Models\Proceso;
use App\Models\TransicionProceso;
use App\Models\User;
use App\Services\ServicioDevolucionSinOfertas;
use App\Services\ServicioDocumento;
use App\Services\ServicioDocumentoFijo;
use App\Services\ServicioLegalizacion;
use App\Services\ServicioOferta;
use App\Services\ServicioPublicacion;
use App\Services\ServicioProceso;
use App\Services\ServicioEnvioRevision;
use App\Services\ServicioInformeComision;
use App\Services\ServicioRemisionCertificaciones;
use App\Services\ServicioRemisionOfertas;
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
        $devoluciones = []; // "Devolver a la unidad requirente" va al final de la fila para no pulsarlo por error

        // Transiciones del flujo disponibles para este usuario en el estado actual
        foreach ($servicio->transicionesDisponibles($proceso, $usuario) as $transicion) {
            // "Remitir documentos legalizados" tiene su propio formulario (PDF firmados, memorando y publicaciones)
            if (in_array($transicion->codigo, ServicioLegalizacion::TRANSICIONES, true)) {
                $acciones[] = $this->accionRemitirLegalizados($proceso, $usuario, $transicion->codigo === ServicioLegalizacion::REABRIR);

                continue;
            }

            // "Reabrir" del analista solo lo usa internamente la devolucion sin ofertas: en Para reapertura reabre la unidad
            if ($transicion->codigo === 'reabrir') {
                continue;
            }

            // Decisiones del flujo, solo despues de registrar las ofertas de la ultima publicacion (las calcula la BD):
            // "Remitir ofertas", "Publicar" de nuevo o devolver el expediente (formulario propio, ver mas abajo).
            if ($transicion->codigo === 'remitir_ofertas') {
                // Remitir ofertas pide tres PDF (Necesidad finalizada y los dos memorandos de la comision): formulario propio
                if (app(ServicioRemisionOfertas::class)->puedeRemitir($proceso, $usuario)) {
                    $acciones[] = $this->accionRemitirOfertas($proceso, $usuario);
                }

                continue;
            }

            // La devolucion sin ofertas (desde Publicado) incluye la reapertura y sus dos PDF: tiene su propia accion.
            if ($transicion->codigo === 'devolver_expediente' && $proceso->estadoProceso?->codigo === 'PUBLICADO') {
                continue;
            }

            // "Enviar a revision" / "Reenviar": la unidad carga los documentos en el propio formulario del boton
            if (in_array($transicion->codigo, ServicioEnvioRevision::TRANSICIONES, true)) {
                $acciones[] = $this->accionEnviarARevision($transicion, $proceso, $usuario);

                continue;
            }

            // La unidad registra las cuatro certificaciones (formulario propio)
            if ($transicion->codigo === ServicioRemisionCertificaciones::TRANSICION) {
                $acciones[] = $this->accionRemitirCertificaciones($proceso, $usuario);

                continue;
            }

            // La Comision Tecnica remite su informe (formulario propio)
            if (in_array($transicion->codigo, ServicioInformeComision::TRANSICIONES, true)) {
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

            $accion = Action::make('transicion_'.$transicion->codigo)
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

            if (in_array($transicion->codigo, ['devolver_legalizacion', 'devolver_publicacion'], true)) {
                $devoluciones[] = $accion;
            } else {
                $acciones[] = $accion;
            }
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
                ->schema([
                    Select::make('analista_id')
                        ->label('Analista de Compras')
                        ->options(fn () => $servicio->analistasConCarga())
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

        if (app(ServicioInformeComision::class)->puedeRemitir($proceso, $usuario)) {
            $acciones[] = $this->accionRemitirInforme($proceso, $usuario);
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

        return array_merge($acciones, $devoluciones);
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
                        // Al final de cada oferta: los archivos del proveedor. Una carpeta se arrastra completa (cada archivo
                        // queda cargado) o se sube comprimida en un ZIP.
                        Hidden::make('nombres_archivos'),
                        FileUpload::make('archivos')
                            ->label('Oferta del proveedor (arrastre la carpeta o seleccione los archivos)')
                            ->helperText('PDF, Word, Excel o ZIP. Puede cargar varios archivos a la vez; los nombres se conservan.')
                            ->disk(ServicioDocumento::DISCO)
                            ->directory('temporal')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/zip', 'application/x-zip-compressed',
                            ])
                            ->multiple()
                            ->maxFiles(ServicioOferta::MAX_ARCHIVOS_POR_OFERTA)
                            ->storeFileNamesIn('nombres_archivos')
                            ->columnSpanFull()
                            ->required(),
                    ]),
            ])
            ->action(function (array $data) use ($proceso, $usuario) {
                $ofertas = collect(array_values((array) $data['ofertas']))->map(fn ($o) => [
                    'identificacion' => (string) ($o['identificacion'] ?? ''),
                    'razon_social' => (string) ($o['razon_social'] ?? ''),
                    'precios' => collect((array) ($o['precios'] ?? []))->mapWithKeys(fn ($p) => [(int) $p['item_id'] => $p['precio'] ?? null])->all(),
                    'archivos' => $this->archivosConNombre((array) ($o['archivos'] ?? []), (array) ($o['nombres_archivos'] ?? [])),
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
                    ->native()
                    ->seconds(false)
                    ->live()
                    ->required(),
                DateTimePicker::make('fecha_limite')
                    ->label('Fecha Límite')
                    ->helperText('Debe ser posterior a la Fecha de Publicación.')
                    ->native()
                    ->seconds(false)
                    ->after('fecha_publicacion')
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
    private function accionRemitirLegalizados(Proceso $proceso, User $usuario, bool $reapertura = false): Action
    {
        $pendientes = app(ServicioDocumento::class)->documentosPorLegalizar($proceso)->load('tipoDocumento:id,nombre');

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
            // la etiqueta es el nombre del tipo (Informe de Necesidad, Especificaciones Técnicas), no el nombre del archivo
            $campos[] = $pdf("legalizado_{$documento->id}", "{$documento->tipoDocumento->nombre} legalizado (PDF firmado electrónicamente)");
        }
        // Orden: legalizados (Informe, Especificaciones), Distributivo, Fichas, Certificado, Validacion, Autorizacion y Memorando
        $adicionales = app(ServicioLegalizacion::class)->tiposAdicionalesPendientes($proceso);
        $distributivoPendiente = $adicionales->contains('codigo', ServicioLegalizacion::TIPO_DISTRIBUTIVO);

        // Distributivo: uno o varios archivos PDF, Word o Excel (obligatorio mientras el proceso no tenga uno)
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
            ->storeFileNamesIn('nombre_distributivo')
            ->required($distributivoPendiente);

        // Fichas tecnicas, certificados, etc. (varios archivos por tipo); solo los que aun no se han cargado
        foreach ($adicionales->where('codigo', '!=', ServicioLegalizacion::TIPO_DISTRIBUTIVO) as $tipo) {
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
        $campos[] = $pdf('memorando', 'Memorando de solicitud de publicación (PDF)');
        $campos[] = TextInput::make('numero_memorando')
            ->label('Número del memorando')
            ->helperText('Ingresar como consta en el Memorando')
            ->maxLength(60)
            ->required();
        $campos[] = Select::make('numero_publicaciones')
            ->label('Número de publicaciones solicitadas')
            ->helperText('Cada publicación puede durar un tiempo distinto. Se indica en horas, en múltiplos de 24: 24 horas = 1 día, 48 horas = 2 días')
            ->options(array_combine(range(1, ServicioLegalizacion::MAX_PUBLICACIONES), range(1, ServicioLegalizacion::MAX_PUBLICACIONES)))
            ->default(1)
            ->live()
            ->required();
        for ($n = 1; $n <= ServicioLegalizacion::MAX_PUBLICACIONES; $n++) {
            $campos[] = TextInput::make("horas_{$n}")
                ->label("Publicación {$n}: duración")
                ->numeric()->integer()
                ->minValue(24)->maxValue(ServicioLegalizacion::MAX_HORAS)->step(24)
                ->rule(fn () => function (string $atributo, $valor, \Closure $fallar) {
                    if ($valor !== null && $valor !== '' && (int) $valor % 24 !== 0) {
                        $fallar('Las horas deben ser 24 o un múltiplo de 24.');
                    }
                })
                ->suffix('horas')
                ->visible(fn (Get $get) => (int) $get('numero_publicaciones') >= $n)
                ->required(fn (Get $get) => (int) $get('numero_publicaciones') >= $n);
        }

        return Action::make($reapertura ? 'transicion_reabrir_unidad' : 'transicion_remitir_legalizados')
            ->label($reapertura ? 'Reabrir proceso' : 'Remitir documentos legalizados')
            ->icon($reapertura ? 'heroicon-o-arrow-path' : 'heroicon-o-paper-airplane')
            ->modalHeading($reapertura ? 'Reabrir proceso' : 'Remitir documentos legalizados')
            ->modalDescription($reapertura
                ? 'Se abre un nuevo ciclo: los datos del ciclo anterior quedan en el historial y el nuevo memorando se guarda en Documentos.'
                : 'Al confirmar, la documentación legalizada pasa al área para su publicación.')
            ->modalSubmitActionLabel($reapertura ? 'Reabrir' : 'Remitir')
            ->modalWidth('3xl')
            ->schema($campos)
            ->action(function (array $data) use ($proceso, $usuario, $pendientes, $adicionales, $reapertura) {
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
                ), $reapertura ? 'Proceso reabierto: nuevo ciclo remitido a Compras' : 'Documentos legalizados remitidos a Compras');
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

    /** El analista remite las ofertas a la unidad: Necesidad de Contratacion Finalizada y los dos memorandos de la comision (PDF). */
    private function accionRemitirOfertas(Proceso $proceso, User $usuario): Action
    {
        return Action::make('transicion_remitir_ofertas')
            ->label('Remitir ofertas')
            ->icon('heroicon-o-paper-airplane')
            ->modalHeading('Remitir ofertas')
            ->modalDescription('Cargue los tres PDF obligatorios. Al confirmar, el proceso pasa a la Comisión Técnica y se crea su usuario de acceso.')
            ->modalSubmitActionLabel('Remitir ofertas')
            ->schema([
                $this->campoPdf('necesidad', 'Necesidad de Contratación Finalizada (PDF)'),
                $this->campoPdf('memo_solicitud', 'Memorando de Solicitud de la Designación de la Comisión (PDF)'),
                $this->campoPdf('memo_designacion', 'Memorando de Designación de la Comisión (PDF)'),
            ])
            ->action(function (array $data) use ($proceso, $usuario) {
                $archivo = fn (string $campo): array => [$this->rutaSubida($data[$campo]), $this->rutaSubida($data['nombre_'.$campo] ?? $data[$campo])];

                $this->ejecutar(function () use ($proceso, $usuario, $archivo) {
                    $c = app(ServicioRemisionOfertas::class)->remitir(
                        $proceso, $usuario, $archivo('necesidad'), $archivo('memo_solicitud'), $archivo('memo_designacion')
                    );
                    // Credenciales iniciales de la comision (la clave es igual al usuario)
                    Notification::make()
                        ->title('Usuario de la Comisión Técnica creado')
                        ->body("Usuario: {$c['usuario']} — Clave inicial: {$c['clave']}. Entréguelas a la comisión.")
                        ->success()->persistent()->send();
                }, 'Ofertas remitidas a la Comisión Técnica');
            });
    }

    /** La unidad envia el proceso a revision cargando alli sus documentos (primer envio) o las versiones corregidas (reenvio). */
    private function accionEnviarARevision(TransicionProceso $transicion, Proceso $proceso, User $usuario): Action
    {
        $campos = app(ServicioEnvioRevision::class)->campos($proceso);
        $reenvio = $proceso->estadoProceso?->codigo === 'EN_CORRECCION';

        return Action::make('transicion_'.$transicion->codigo)
            ->label($transicion->nombre)
            ->modalHeading($transicion->nombre)
            ->modalDescription($reenvio
                ? 'Cargue una nueva versión de cada documento con observaciones.'
                : 'Cargue los documentos del proceso, ambos son obligatorios para la revisión.')
            ->modalSubmitActionLabel('Enviar')
            ->schema(collect($campos)->map(fn (array $c) => FileUpload::make($c['clave'])
                ->label($c['etiqueta'].' ('.$c['tipo']->formatosLegibles().')')
                ->helperText($c['documento'] !== null && ! $c['obligatorio'] ? 'Ya está cargado: adjunte un archivo solo si desea reemplazarlo con una versión nueva.' : null)
                ->disk(ServicioDocumento::DISCO)
                ->directory('temporal')
                ->visibility('private')
                ->acceptedFileTypes($c['tipo']->mimesPermitidos())
                ->storeFileNamesIn('nombre_'.$c['clave'])
                ->required($c['obligatorio']))->all())
            ->action(function (array $data) use ($proceso, $usuario, $campos, $reenvio) {
                $archivos = [];
                foreach ($campos as $c) {
                    $clave = $c['clave'];
                    $ruta = $this->rutaSubida($data[$clave] ?? null);
                    $archivos[$clave] = [$ruta !== '' ? $ruta : null, $this->rutaSubida($data['nombre_'.$clave] ?? null) ?: null];
                }

                $this->ejecutar(fn () => app(ServicioEnvioRevision::class)->enviar($proceso, $usuario, $archivos),
                    $reenvio ? 'Documentos corregidos: proceso enviado de nuevo a revisión' : 'Proceso enviado a revisión');
            });
    }

    /** La unidad requirente registra Certificacion POA, PAC, Presupuestaria y Autorizacion de gasto (un PDF legalizado cada uno). */
    private function accionRemitirCertificaciones(Proceso $proceso, User $usuario): Action
    {
        return Action::make('transicion_remitir_certificaciones')
            ->label('Remitir certificaciones')
            ->icon('heroicon-o-paper-airplane')
            ->modalHeading('Remitir certificaciones')
            ->modalDescription('Cargue los documentos PDF legalizados por cada ítem.')
            ->modalSubmitActionLabel('Remitir')
            ->schema(collect(ServicioRemisionCertificaciones::DOCUMENTOS)
                ->map(fn (array $d, string $campo) => FileUpload::make($campo)
                    ->label($d[1].' (PDF legalizado; puede adjuntar varios)')
                    ->disk(ServicioDocumento::DISCO)
                    ->directory('temporal')
                    ->visibility('private')
                    ->acceptedFileTypes(['application/pdf'])
                    ->multiple()
                    ->storeFileNamesIn('nombre_'.$campo)
                    ->required())
                ->values()->all())
            ->action(function (array $data) use ($proceso, $usuario) {
                $archivos = [];
                foreach (array_keys(ServicioRemisionCertificaciones::DOCUMENTOS) as $campo) {
                    $nombres = (array) ($data['nombre_'.$campo] ?? []);
                    foreach ((array) ($data[$campo] ?? []) as $ruta) {
                        $archivos[$campo][] = [(string) $ruta, (string) ($nombres[$ruta] ?? basename((string) $ruta))];
                    }
                }

                $this->ejecutar(fn () => app(ServicioRemisionCertificaciones::class)->remitir($proceso, $usuario, $archivos), 'Certificaciones remitidas');
            });
    }

    /** La Comision Tecnica remite su informe: devolucion de expediente o seleccion de proveedor (un PDF por documento). */
    private function accionRemitirInforme(Proceso $proceso, User $usuario): Action
    {
        $esSeleccion = fn (Get $get): bool => $get('tipo_informe') === ServicioInformeComision::SELECCION;
        $hayTipo = fn (Get $get): bool => in_array($get('tipo_informe'), [ServicioInformeComision::DEVOLUCION, ServicioInformeComision::SELECCION], true);

        return Action::make('remitir_informe')
            ->label('Remitir Informe')
            ->icon('heroicon-o-paper-airplane')
            ->modalHeading('Remitir Informe')
            ->modalDescription('Seleccione el tipo de informe y cargue un PDF por cada documento.')
            ->modalSubmitActionLabel('Remitir Informe')
            ->schema([
                Select::make('tipo_informe')
                    ->label('Tipo de Documento')
                    ->options([
                        ServicioInformeComision::DEVOLUCION => 'Informe de Recomendación de Devolución de Expediente',
                        ServicioInformeComision::SELECCION => 'Informe de Recomendación de Selección de Proveedor',
                    ])
                    ->live()
                    ->required(),
                $this->campoPdf('memorando', 'Memorando de remisión de informe (PDF)')->visible($hayTipo),
                $this->campoPdf('informe_devolucion', 'Informe de Recomendación de Devolución de Expediente (PDF)')
                    ->visible(fn (Get $get) => $get('tipo_informe') === ServicioInformeComision::DEVOLUCION),
                $this->campoPdf('informe_seleccion', 'Informe de Recomendación de Selección de Proveedor (PDF)')->visible($esSeleccion),
                $this->campoPdf('acta', 'Acta de Constancia de Recepción de Ofertas (PDF)')->visible($esSeleccion),
                $this->campoPdf('firmas', 'Validación de firmas (PDF)')->visible($esSeleccion),
            ])
            ->action(function (array $data) use ($proceso, $usuario) {
                $archivo = fn (string $campo): array => [$this->rutaSubida($data[$campo] ?? null), $this->rutaSubida($data['nombre_'.$campo] ?? ($data[$campo] ?? null))];
                $tipo = $data['tipo_informe'];
                $archivos = ['memorando' => $archivo('memorando')];
                if ($tipo === ServicioInformeComision::DEVOLUCION) {
                    $archivos['informe'] = $archivo('informe_devolucion');
                } else {
                    $archivos += ['informe' => $archivo('informe_seleccion'), 'acta' => $archivo('acta'), 'firmas' => $archivo('firmas')];
                }

                $this->ejecutar(fn () => app(ServicioInformeComision::class)->remitir($proceso, $usuario, $tipo, $archivos), 'Informe remitido');
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

    /**
     * Une cada ruta temporal con su nombre original: por la ruta, por el orden o, si no hay nombre, el de la ruta.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function archivosConNombre(array $rutas, array $nombres): array
    {
        $porOrden = array_values($nombres);
        $resultado = [];
        foreach (array_values($rutas) as $i => $valor) {
            $ruta = $this->rutaSubida($valor);
            $nombre = $nombres[$ruta] ?? $nombres[$valor] ?? $porOrden[$i] ?? basename($ruta);
            $resultado[] = [$ruta, is_array($nombre) ? (string) array_values($nombre)[0] : (string) $nombre];
        }

        return $resultado;
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
            $servicioDoc = app(ServicioDocumento::class);
            $enRevision = $servicioDoc->documentosEnRevision($proceso);
            // con una version nueva del analista el documento viene marcado: se debe corregir (no se bloquea la casilla porque
            // Filament la sacaria de las opciones validas y fallaria validation.in; el servicio la incluye aunque se desmarque)
            $bloqueados = $enRevision->filter(fn ($d) => $servicioDoc->tieneVersionDelAnalista($d))->pluck('id')->all();
            $documentos = $enRevision->mapWithKeys(fn ($d) => [$d->id => $d->titulo.(in_array($d->id, $bloqueados, true) ? ' (nueva versión, se debe corregir)' : '')]);
            array_unshift($campos, CheckboxList::make('documentos')
                ->label('Documentos que la unidad debe corregir')
                ->helperText('Marque solo los que tienen observaciones: la unidad deberá cargar una nueva versión de cada uno.')
                ->options($documentos)
                ->default($bloqueados)
                // los bloqueados siempre se remiten (lo garantiza el servicio aunque el formulario no los envie)
                ->required($bloqueados === []));
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
