<?php

namespace Tests\Feature;

use App\Filament\Resources\Procesos\Pages\ViewProceso;
use App\Models\Proceso;
use App\Models\TipoDocumento;
use App\Models\TransicionProceso;
use App\Models\User;
use App\Services\ServicioDevolucionSinOfertas;
use App\Services\ServicioDocumento;
use App\Services\ServicioDocumentoFijo;
use App\Services\ServicioLegalizacion;
use App\Services\ServicioOferta;
use App\Services\ServicioProceso;
use App\Services\ServicioInformeComision;
use App\Services\ServicioPublicacion;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreaProcesos;
use Tests\TestCase;

/** Ofertas tras la publicacion: proveedores, precio por item, totales y habilitacion de republicar / remitir ofertas. */
class OfertasTest extends TestCase
{
    use CreaProcesos;
    use RefreshDatabase;

    private ServicioProceso $procesos;

    private ServicioOferta $ofertas;

    private User $unidad;

    private User $analista;

    private User $jefe;

    private Proceso $proceso;

    /** @var array<int, int> ids de los items (linea 1 y 2) */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ServicioDocumento::DISCO);
        $this->procesos = app(ServicioProceso::class);
        $this->ofertas = app(ServicioOferta::class);

        $this->unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $this->analista = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $this->jefe = User::factory()->conRoles('COMPRAS_JEFE')->create();

        $p = $this->proceso($this->unidad);
        $this->items[1] = DB::table('proceso_items')->insertGetId(['proceso_id' => $p->id, 'numero_linea' => 1, 'cum' => 'CUM-1', 'descripcion' => 'Paracetamol 500 mg', 'cantidad' => 10]);
        $this->items[2] = DB::table('proceso_items')->insertGetId(['proceso_id' => $p->id, 'numero_linea' => 2, 'cum' => 'CUM-2', 'descripcion' => 'Ibuprofeno 400 mg', 'cantidad' => 4]);
        $this->asignar($p, $this->unidad, 'UNIDAD_REQUIRENTE', $this->unidad);
        foreach (['INFORME_NECESIDAD', 'ESPECIFICACIONES_TECNICAS'] as $tipo) {
            [$ruta] = $this->pdfTemporal($tipo.'.pdf', "%PDF-1.4\n{$tipo}");
            app(ServicioDocumento::class)->crearDocumento($p, TipoDocumento::where('codigo', $tipo)->firstOrFail(), null, $ruta, $tipo.'.pdf', null, $this->unidad);
        }
        $this->procesos->aplicarTransicion($p->fresh(), TransicionProceso::where('codigo', 'enviar_a_revision')->firstOrFail(), $this->unidad);
        $this->procesos->asignarAnalista($p->fresh(), $this->analista, $this->jefe);
        $this->procesos->aplicarTransicion($p->fresh(), TransicionProceso::where('codigo', 'validar_documentacion')->firstOrFail(), $this->analista);
        $this->proceso = $p->fresh();

        // la unidad pidio dos publicaciones; Compras asigna y publica la primera
        $this->remitirLegalizados($this->proceso, $this->unidad, [24, 48]);
        $this->procesos->asignarAnalista($this->proceso->fresh(), $this->analista, $this->jefe);
        $this->publicar('NC-2026-001');
    }

    private function publicar(string $necesidad): void
    {
        app(ServicioPublicacion::class)->registrar($this->proceso->fresh(), $this->analista, $necesidad, 'https://sitio.ec/p', '2026-12-01 08:00', '2026-12-05 17:00');
    }

    private function oferta(string $id, string $razon, float $p1, float $p2, ?array $archivos = null): array
    {
        return [
            'identificacion' => $id, 'razon_social' => $razon, 'precios' => [$this->items[1] => $p1, $this->items[2] => $p2],
            'archivos' => $archivos ?? [$this->pdfTemporal("oferta_{$id}.pdf")],
        ];
    }

    private function registrar(array $ofertas, ?User $usuario = null): void
    {
        $this->ofertas->registrar($this->proceso->fresh(), $usuario ?? $this->analista, $ofertas);
    }

    /** El analista remite las ofertas con los tres PDF obligatorios (Necesidad finalizada y los dos memorandos de la comision). */
    private function remitirOfertas(): array
    {
        return app(\App\Services\ServicioRemisionOfertas::class)->remitir(
            $this->proceso->fresh(), $this->analista, $this->pdfTemporal('necesidad_finalizada.pdf'), $this->pdfTemporal('memo_solicitud.pdf'), $this->pdfTemporal('memo_designacion.pdf')
        );
    }

    private function tres(): array
    {
        return [
            $this->oferta('1790000000001', 'Farma Uno S.A.', 1.5, 2.25),
            $this->oferta('1790000000002', 'Farma Dos S.A.', 1.4, 2.5),
            $this->oferta('0912345678', 'Juan Perez (cedula)', 1.6, 2),
        ];
    }

    public function test_registra_ofertas_con_precio_por_item_y_calcula_los_totales(): void
    {
        $this->assertSame('PUBLICADO', $this->proceso->fresh()->estadoProceso->codigo);
        $this->assertTrue($this->ofertas->puedeRegistrar($this->proceso->fresh(), $this->analista));

        $this->registrar($this->tres());

        $proceso = $this->proceso->fresh();
        $this->assertSame(3, $this->ofertas->cantidad($proceso));
        $this->assertSame(6, DB::table('oferta_items')->count());
        $this->assertSame(3, DB::table('proveedores')->count());
        $this->assertSame(['RECIBIDA'], DB::table('ofertas')->join('estados_oferta as e', 'e.id', '=', 'ofertas.estado_oferta_id')->pluck('e.codigo')->unique()->values()->all());

        $resumen = $this->ofertas->resumen($proceso);
        $uno = $resumen->firstWhere('identificacion', '1790000000001');
        $this->assertSame(15.0, $uno['items'][0]['total']);   // 10 x 1.50
        $this->assertSame(9.0, $uno['items'][1]['total']);    // 4 x 2.25
        $this->assertSame(24.0, $uno['total']);
        $this->assertSame(1, $uno['publicacion']);
        $this->assertSame(24.0, $resumen->firstWhere('identificacion', '0912345678')['total']);   // 10 x 1.60 + 4 x 2.00 (cedula de 10 digitos)
        $this->assertDatabaseHas('auditoria', ['evento' => 'proceso.ofertas_registradas', 'entidad_id' => $proceso->id]);
    }

    public function test_un_proveedor_conocido_se_reutiliza(): void
    {
        $this->registrar([$this->oferta('1790000000001', 'Farma Uno S.A.', 1, 1)]);
        $this->publicar('NC-2026-002');
        $this->registrar([$this->oferta('1790000000001', 'Farma Uno S.A.', 0.9, 1)]);

        $this->assertSame(1, DB::table('proveedores')->count());
        $this->assertSame(2, DB::table('ofertas')->count());
    }

    public function test_datos_invalidos_no_guardan_nada(): void
    {
        $casos = [
            'ruc corto' => [[$this->oferta('12345', 'X', 1, 1)], 'RUC debe tener 13 dígitos'],
            'ruc con letras' => [[$this->oferta('17900000000AB', 'X', 1, 1)], 'RUC debe tener 13 dígitos'],
            'razon vacia' => [[$this->oferta('1790000000001', '  ', 1, 1)], 'razón social'],
            'proveedor repetido' => [[$this->oferta('1790000000001', 'A', 1, 1), $this->oferta('1790000000001', 'B', 1, 1)], 'repetido'],
            'precio negativo' => [[$this->oferta('1790000000001', 'A', -1, 1)], 'precio unitario del ítem 1'],
            'precio faltante' => [[['identificacion' => '1790000000001', 'razon_social' => 'A', 'precios' => [$this->items[1] => 1]]], 'precio unitario del ítem 2'],
        ];
        foreach ($casos as $nombre => [$ofertas, $mensaje]) {
            try {
                $this->registrar($ofertas);
                $this->fail("Debio rechazar: {$nombre}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage(), $nombre);
            }
            $this->assertSame(0, DB::table('ofertas')->count(), $nombre);
            $this->assertSame(0, DB::table('proveedores')->count(), $nombre);
        }
    }

    public function test_solo_el_analista_asignado_registra_y_una_sola_vez_por_publicacion(): void
    {
        $proceso = $this->proceso->fresh();
        $this->assertFalse($this->ofertas->puedeRegistrar($proceso, $this->unidad));
        $this->assertFalse($this->ofertas->puedeRegistrar($proceso, $this->jefe));
        $this->assertFalse($this->ofertas->puedeRegistrar($proceso, User::factory()->conRoles('COMPRAS_ANALISTA')->create()));

        $this->registrar([$this->oferta('1790000000001', 'A', 1, 1)]);
        $this->assertFalse($this->ofertas->puedeRegistrar($this->proceso->fresh(), $this->analista), 'ya registro las de esta publicacion');

        $this->expectException(DomainException::class);
        $this->registrar([$this->oferta('1790000000002', 'B', 1, 1)]);
    }

    public function test_con_menos_de_tres_ofertas_se_republica_hasta_el_numero_solicitado(): void
    {
        $publicacion = app(ServicioPublicacion::class);

        // recien publicado: todavia no se decide nada hasta registrar las ofertas
        $this->assertFalse($publicacion->puedeRepublicar($this->proceso->fresh()));
        $this->assertFalse($publicacion->puedePublicar($this->proceso->fresh(), $this->analista));

        $this->registrar([$this->oferta('1790000000001', 'A', 1, 1)]);
        $this->assertTrue($publicacion->puedeRepublicar($this->proceso->fresh()), '1 oferta y 1 de 2 publicaciones');
        $this->assertTrue($publicacion->puedePublicar($this->proceso->fresh(), $this->analista));

        $this->publicar('NC-2026-002');
        $this->assertSame('PUBLICADO', $this->proceso->fresh()->estadoProceso->codigo);
        $this->assertSame(2, $this->proceso->fresh()->publicaciones()->count());
        $this->assertSame('NC-2026-002', $this->proceso->fresh()->publicaciones()->reorder('secuencia', 'desc')->first()->codigo_nc);

        // las 2 publicaciones solicitadas ya se hicieron: no se habilita una tercera
        $this->assertFalse($publicacion->puedeRepublicar($this->proceso->fresh()));
        $this->assertFalse($publicacion->puedePublicar($this->proceso->fresh(), $this->analista));
        // y se pueden registrar las ofertas de la segunda publicacion
        $this->assertTrue($this->ofertas->puedeRegistrar($this->proceso->fresh(), $this->analista));
    }

    public function test_con_tres_ofertas_no_se_republica_y_se_habilita_remitir_ofertas(): void
    {
        $this->registrar($this->tres());
        $this->assertFalse(app(ServicioPublicacion::class)->puedePublicar($this->proceso->fresh(), $this->analista));

        $this->actingAs($this->analista);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionVisible('transicion_remitir_ofertas')
            ->assertActionDoesNotExist('transicion_publicar')
            ->assertActionDoesNotExist('ofertas')
            ->assertSee('Farma Uno S.A.')
            ->assertSee('$ 24.00');
    }

    public function test_la_pantalla_muestra_ofertas_y_republicar_segun_corresponda(): void
    {
        $this->actingAs($this->analista);
        $archivo = $this->pdfTemporal('x.pdf');

        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionVisible('ofertas')
            // recien publicado: solo "Ofertas"; las decisiones aparecen despues de registrarlas
            ->assertActionDoesNotExist('transicion_publicar')
            ->assertActionDoesNotExist('transicion_remitir_ofertas')
            ->callAction('ofertas', [
                'numero_ofertas' => 1,
                'ofertas' => [[
                    'identificacion' => '1790000000001',
                    'razon_social' => 'Farma Uno S.A.',
                    'precios' => [
                        ['item_id' => $this->items[1], 'linea' => 1, 'descripcion' => 'Paracetamol 500 mg', 'cantidad' => 10, 'precio' => '1.5'],
                        ['item_id' => $this->items[2], 'linea' => 2, 'descripcion' => 'Ibuprofeno 400 mg', 'cantidad' => 4, 'precio' => '2.25'],
                    ],
                    'archivos' => [$archivo[0]],
                    'nombres_archivos' => [$archivo[0] => 'oferta farma uno.pdf'],
                ]],
            ])
            ->assertHasNoActionErrors();

        // si la accion fallo, el mensaje del error aparece aqui
        $errores = collect(session('filament.notifications', []))->where('status', 'danger')->pluck('body')->all();
        $this->assertSame([], $errores, 'la accion no debio fallar');
        $this->assertSame(1, $this->ofertas->cantidad($this->proceso->fresh()));
        $this->assertSame(24.0, $this->ofertas->resumen($this->proceso->fresh())->first()['total']);
        $this->assertSame('oferta farma uno.pdf', $this->ofertas->resumen($this->proceso->fresh())->first()['archivos'][0]['nombre']);

        // con 1 oferta (< 3) y 1 de 2 publicaciones: se habilita Publicar y no Remitir ofertas
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionDoesNotExist('ofertas')
            ->assertActionVisible('transicion_publicar')
            ->assertActionDoesNotExist('transicion_remitir_ofertas');
    }

    public function test_cero_ofertas_deja_constancia_y_habilita_publicar_de_nuevo(): void
    {
        $this->registrar([]);

        $this->assertSame(0, DB::table('ofertas')->count());
        $this->assertTrue($this->ofertas->ofertasRegistradas($this->proceso->fresh()));
        $this->assertFalse($this->ofertas->puedeRegistrar($this->proceso->fresh(), $this->analista));
        $this->assertTrue(app(ServicioPublicacion::class)->puedePublicar($this->proceso->fresh(), $this->analista));
    }

    public function test_la_unidad_no_ve_el_boton_de_ofertas(): void
    {
        $this->actingAs($this->unidad);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionDoesNotExist('ofertas');
    }

    // ---- E4: decisiones tras las ofertas, documentacion de la unidad, devolucion y re-publicacion ----

    public function test_el_minimo_de_tres_cuenta_el_total_de_ofertas_de_todas_las_publicaciones(): void
    {
        $this->registrar([$this->oferta('1790000000001', 'A', 1, 1), $this->oferta('1790000000002', 'B', 1, 1)]);
        $this->assertSame('republicar', $this->ofertas->decision($this->proceso->fresh()), '2 ofertas y falta 1 publicacion');

        $this->publicar('NC-2026-002');
        $this->registrar([$this->oferta('1790000000003', 'C', 1, 1), $this->oferta('1790000000004', 'D', 1, 1)]);

        // 2 + 2 = 4 ofertas con las 2 publicaciones hechas
        $proceso = $this->proceso->fresh();
        $this->assertSame(4, $this->ofertas->cantidad($proceso));
        $this->assertSame('remitir', $this->ofertas->decision($proceso));
        $this->assertTrue($this->ofertas->puedeRemitir($proceso));
        $this->assertFalse($this->ofertas->puedeDevolver($proceso));
    }

    public function test_tres_ofertas_en_la_primera_publicacion_ya_se_remiten_aunque_falte_una_publicacion(): void
    {
        $this->registrar($this->tres());
        $this->assertSame('remitir', $this->ofertas->decision($this->proceso->fresh()));
    }

    public function test_con_publicaciones_agotadas_y_al_menos_una_oferta_se_remite(): void
    {
        $this->registrar([]);
        $this->publicar('NC-2026-002');
        $this->registrar([$this->oferta('1790000000001', 'A', 1, 1)]);

        $this->assertSame('remitir', $this->ofertas->decision($this->proceso->fresh()));
    }

    public function test_la_decision_no_existe_hasta_registrar_las_ofertas_de_la_ultima_publicacion(): void
    {
        $this->assertNull($this->ofertas->decision($this->proceso->fresh()));
        $this->registrar([]);
        $this->assertSame('republicar', $this->ofertas->decision($this->proceso->fresh()));
        $this->publicar('NC-2026-002');
        $this->assertNull($this->ofertas->decision($this->proceso->fresh()), 'la nueva publicacion aun no tiene ofertas registradas');
    }

    public function test_remitir_ofertas_crea_el_usuario_de_la_comision_tecnica_y_pasa_el_proceso_a_comision(): void
    {
        $this->registrar($this->tres());
        $c = $this->remitirOfertas();

        $proceso = $this->proceso->fresh();
        $this->assertSame('EN_COMISION', $proceso->estadoProceso->codigo);
        $this->assertSame('PRECONTRACTUAL', $proceso->estadoMacro->codigo, 'la fase Precontractual empieza con la comision tecnica');

        // usuario propio del proceso, con rol Comision Tecnica y clave temporal que solo se guarda cifrada
        $comision = User::where('email', $c['usuario'])->firstOrFail();
        $this->assertTrue($comision->activo);
        $this->assertTrue($comision->tieneRol('COMISION_TECNICA'));
        $this->assertStringContainsString($proceso->codigo, $comision->name);
        $partes = explode('-', $proceso->codigo);
        $local = 'comision.'.$partes[count($partes) - 2].'.'.last($partes);
        $this->assertSame("{$local}@ailep.com", $c['usuario']);
        $this->assertSame($local, $c['clave'], 'la clave inicial es el mismo usuario');
        $this->assertTrue($comision->debe_cambiar_clave, 'debe cambiar la clave en el primer ingreso');
        $this->assertTrue(Hash::check($c['clave'], $comision->password));
        $this->assertNotSame($c['clave'], $comision->getRawOriginal('password'));
        $this->assertTrue($this->procesos->estaAsignado($proceso, $comision));

        // ve el proceso, remite informes y exporta el expediente; Compras y la unidad no remiten informes
        $informes = app(ServicioInformeComision::class);
        $this->assertTrue($informes->puedeRemitir($proceso, $comision));
        $this->assertFalse($informes->puedeRemitir($proceso, $this->analista));
        $this->assertFalse($informes->puedeRemitir($proceso, $this->unidad));
        $this->assertTrue(app(\App\Services\ServicioExpedientePublicacion::class)->puedeGenerar($proceso, $comision));

        $this->actingAs($comision);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionVisible('remitir_informe')
            ->assertSee('Ofertas')
            ->assertDontSee('Solicitud de publicación');

        $this->actingAs($this->analista);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionDoesNotExist('remitir_informe')
            ->assertActionDoesNotExist('transicion_remitir_documentacion');
    }

    /** @return array{0: Proceso, 1: User} proceso en comision y el usuario de la comision */
    private function procesoEnComision(): array
    {
        $this->registrar($this->tres());
        $c = $this->remitirOfertas();

        return [$this->proceso->fresh(), User::where('email', $c['usuario'])->firstOrFail()];
    }

    public function test_informe_de_devolucion_de_la_comision_pide_dos_pdf_y_deja_el_proceso_para_reapertura(): void
    {
        [$proceso, $comision] = $this->procesoEnComision();
        $informes = app(ServicioInformeComision::class);
        $tipos = TipoDocumento::whereIn('codigo', ['MEMORANDO_REMISION_INFORME', 'INFORME_RECOMENDACION_DEVOLUCION'])->pluck('id');
        $this->assertCount(2, $tipos);

        // falta el informe: no cambia nada y no quedan temporales
        try {
            $informes->remitir($proceso, $comision, ServicioInformeComision::DEVOLUCION, ['memorando' => $this->pdfTemporal('memo.pdf'), 'informe' => []]);
            $this->fail('Debio exigir el informe');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Informe de Recomendación de Devolución de Expediente', $e->getMessage());
        }
        $this->assertSame('EN_COMISION', $this->proceso->fresh()->estadoProceso->codigo);
        $this->assertSame(0, DB::table('documentos')->whereIn('tipo_documento_id', $tipos)->count());
        $this->assertSame([], Storage::disk(ServicioDocumento::DISCO)->allFiles('temporal'));

        $informes->remitir($proceso, $comision, ServicioInformeComision::DEVOLUCION, [
            'memorando' => $this->pdfTemporal('memo.pdf'), 'informe' => $this->pdfTemporal('informe.pdf'),
        ]);

        $this->assertSame('PARA_REAPERTURA', $this->proceso->fresh()->estadoProceso->codigo);
        $estados = DB::table('documentos as d')->join('estados_documento as e', 'e.id', '=', 'd.estado_documento_id')
            ->whereIn('d.tipo_documento_id', $tipos)->where('d.proceso_id', $proceso->id)->pluck('e.codigo');
        $this->assertCount(2, $estados);
        $this->assertSame(['LEGALIZADO'], $estados->unique()->values()->all());
        $this->assertSame(1, DB::table('devoluciones')->where('proceso_id', $proceso->id)->count());
        // la comision sigue activa pero en solo lectura: ve el proceso y ya no remite ni carga documentos
        $proceso = $proceso->fresh();
        $this->assertTrue($comision->fresh()->activo);
        $this->assertTrue($this->procesos->estaAsignado($proceso, $comision));
        $this->assertTrue(app(\App\Services\ServicioDocumento::class)->puedeVerProceso($proceso, $comision->fresh()));
        $this->assertFalse($informes->puedeRemitir($proceso, $comision->fresh()));
        $this->assertFalse(app(\App\Services\ServicioDocumento::class)->puedeSubir($proceso, $comision->fresh()));
        $this->assertTrue(app(\App\Services\ServicioDocumento::class)->tiposDisponibles($proceso, $comision->fresh())->isEmpty());
    }

    public function test_informe_de_seleccion_de_proveedor_pide_los_cuatro_pdf(): void
    {
        [$proceso, $comision] = $this->procesoEnComision();
        $informes = app(ServicioInformeComision::class);
        // cada archivo se crea solo si no es el que falta (un temporal descartado por el test quedaria huerfano)
        $completo = fn (?string $falta = null) => collect(['memorando', 'informe', 'acta', 'firmas'])
            ->mapWithKeys(fn ($c) => [$c => $c === $falta ? [] : $this->pdfTemporal($c.'.pdf')])->all();

        foreach (['memorando', 'informe', 'acta', 'firmas'] as $falta) {
            $archivos = $completo($falta);
            try {
                $informes->remitir($proceso, $comision, ServicioInformeComision::SELECCION, $archivos);
                $this->fail("Debio exigir: {$falta}");
            } catch (DomainException $e) {
                $this->assertStringContainsString('Cargue el documento', $e->getMessage(), $falta);
            }
            $this->assertSame('EN_COMISION', $this->proceso->fresh()->estadoProceso->codigo, $falta);
            $this->assertSame([], Storage::disk(ServicioDocumento::DISCO)->allFiles('temporal'), "sin temporales ({$falta})");
        }

        $informes->remitir($proceso, $comision, ServicioInformeComision::SELECCION, $completo());

        $this->assertSame('APROBADO', $this->proceso->fresh()->estadoProceso->codigo);
        $this->assertSame(4, DB::table('documentos as d')->join('estados_documento as e', 'e.id', '=', 'd.estado_documento_id')
            ->join('tipos_documento as t', 't.id', '=', 'd.tipo_documento_id')
            ->whereIn('t.codigo', ['MEMORANDO_REMISION_INFORME', 'INFORME_SELECCION_PROVEEDOR', 'ACTA_RECEPCION_OFERTAS', 'VALIDACION_FIRMAS_INFORME'])
            ->where('d.proceso_id', $proceso->id)->where('e.codigo', 'LEGALIZADO')->count());
        $this->assertSame(0, DB::table('devoluciones')->where('proceso_id', $proceso->id)->count());
    }

    public function test_sin_ofertas_y_publicaciones_agotadas_el_analista_devuelve_y_la_unidad_re_publica_en_un_ciclo_nuevo(): void
    {
        $codigo = $this->proceso->fresh()->codigo;
        $this->registrar([]);
        $this->publicar('NC-2026-002');
        $this->registrar([]);

        $proceso = $this->proceso->fresh();
        $devolucion = app(ServicioDevolucionSinOfertas::class);
        $this->assertSame('devolver', $this->ofertas->decision($proceso));
        $this->assertTrue($devolucion->puedeDevolver($proceso, $this->analista));
        $this->assertFalse($devolucion->puedeDevolver($proceso, $this->unidad));
        $this->assertFalse($devolucion->puedeDevolver($proceso, $this->jefe), 'solo el analista asignado');

        // el analista no puede "legalizar" un PDF cualquiera: solo registrar los de la devolucion
        $devolucion->devolver($proceso, $this->analista, $this->pdfTemporal('memo_devolucion.pdf'), $this->pdfTemporal('informe.pdf'));

        $proceso = $this->proceso->fresh();
        $this->assertSame('PARA_REPUBLICAR', $proceso->estadoProceso->codigo);
        $this->assertSame(2, $proceso->ciclo);
        $this->assertSame($codigo, $proceso->codigo, 'conserva el codigo PE-AILEP');
        $this->assertSame(1, DB::table('devoluciones')->where('proceso_id', $proceso->id)->where('ciclo', 1)->count());
        $this->assertSame(['devuelta'], DB::table('solicitudes_publicacion')->where('proceso_id', $proceso->id)->pluck('estado')->all());
        $legalizados = DB::table('documentos as d')->join('estados_documento as e', 'e.id', '=', 'd.estado_documento_id')
            ->join('tipos_documento as t', 't.id', '=', 'd.tipo_documento_id')
            ->whereIn('t.codigo', ['MEMORANDO_DEVOLUCION', 'INFORME_DEVOLUCION_SIN_OFERTAS'])->pluck('e.codigo');
        $this->assertSame(['LEGALIZADO', 'LEGALIZADO'], $legalizados->all());

        // la unidad vuelve a pedir memorando, horas y numero de publicaciones; los documentos legalizados se conservan
        $this->assertSame(0, app(ServicioDocumento::class)->documentosPorLegalizar($proceso)->count());
        $this->assertTrue(app(ServicioLegalizacion::class)->puedeRemitir($proceso, $this->unidad));
        $this->remitirLegalizados($proceso, $this->unidad, [24], 'AIL-GER-2026-002-M');

        $proceso = $this->proceso->fresh();
        $this->assertSame('LEGALIZADO', $proceso->estadoProceso->codigo);
        $this->assertSame(2, DB::table('solicitudes_publicacion')->where('proceso_id', $proceso->id)->count());

        $this->procesos->asignarAnalista($proceso, $this->analista, $this->jefe);
        $this->publicar('NC-2026-003');

        $proceso = $this->proceso->fresh();
        $this->assertSame('PUBLICADO', $proceso->estadoProceso->codigo);
        $this->assertSame(1, $proceso->publicaciones()->count(), 'las publicaciones del ciclo 1 no cuentan');
        $this->assertSame(3, DB::table('publicaciones')->where('proceso_id', $proceso->id)->count());
        $this->assertSame(0, $this->ofertas->cantidad($proceso));
        $this->assertTrue($this->ofertas->puedeRegistrar($proceso, $this->analista));
    }

    public function test_la_devolucion_exige_los_dos_pdf_y_no_cambia_nada_si_falla(): void
    {
        $this->registrar([]);
        $this->publicar('NC-2026-002');
        $this->registrar([]);

        try {
            app(ServicioDevolucionSinOfertas::class)->devolver($this->proceso->fresh(), $this->analista, $this->pdfTemporal('memo.pdf'), []);
            $this->fail('Debio exigir el informe');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Informe de devolución', $e->getMessage());
        }

        $this->assertSame('PUBLICADO', $this->proceso->fresh()->estadoProceso->codigo);
        $this->assertSame(1, $this->proceso->fresh()->ciclo);
        $this->assertSame(0, DB::table('devoluciones')->count());
    }

    public function test_no_se_devuelve_si_hay_ofertas_o_aun_se_puede_republicar(): void
    {
        $this->registrar([]);   // 1 de 2 publicaciones: se republica
        $this->expectException(DomainException::class);
        app(ServicioDevolucionSinOfertas::class)->devolver($this->proceso->fresh(), $this->analista, $this->pdfTemporal('m.pdf'), $this->pdfTemporal('i.pdf'));
    }

    public function test_la_pantalla_habilita_cada_boton_segun_la_decision(): void
    {
        $this->actingAs($this->analista);
        $ver = fn () => Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()]);

        $this->registrar([]);
        $ver()->assertActionVisible('transicion_publicar')
            ->assertActionDoesNotExist('transicion_remitir_ofertas')
            ->assertActionDoesNotExist('devolver_sin_ofertas');

        $this->publicar('NC-2026-002');
        $this->registrar([]);
        $ver()->assertActionVisible('devolver_sin_ofertas')
            ->assertActionDoesNotExist('transicion_publicar')
            ->assertActionDoesNotExist('transicion_remitir_ofertas')
            ->assertActionDoesNotExist('transicion_devolver_expediente');

        $this->actingAs($this->unidad);
        $ver()->assertActionDoesNotExist('devolver_sin_ofertas');
    }

    public function test_remitir_ofertas_exige_necesidad_finalizada_y_los_dos_memorandos(): void
    {
        $this->registrar($this->tres());
        $remision = app(\App\Services\ServicioRemisionOfertas::class);
        $tipos = TipoDocumento::whereIn('codigo', ['NECESIDAD_CONTRATACION_FINALIZADA', 'MEMORANDO_SOLICITUD_DESIGNACION_COMISION', 'MEMORANDO_DESIGNACION_COMISION'])->pluck('id');
        $this->assertCount(3, $tipos);

        // solo el analista asignado puede remitir
        $this->assertTrue($remision->puedeRemitir($this->proceso->fresh(), $this->analista));
        $this->assertFalse($remision->puedeRemitir($this->proceso->fresh(), $this->unidad));

        // cada caso crea su temporal justo antes de ejecutarse
        $casos = [
            'sin memorando de designacion' => [fn () => [$this->pdfTemporal('necesidad.pdf'), $this->pdfTemporal('solicitud.pdf'), []], 'Memorando de Designación de la Comisión'],
            'sin memorando de solicitud' => [fn () => [$this->pdfTemporal('necesidad.pdf'), [], $this->pdfTemporal('designacion.pdf')], 'Memorando de Solicitud'],
            'sin necesidad' => [fn () => [[], $this->pdfTemporal('solicitud.pdf'), $this->pdfTemporal('designacion.pdf')], 'Necesidad de Contratación Finalizada'],
        ];
        foreach ($casos as $caso => [$crear, $mensaje]) {
            [$necesidad, $solicitud, $designacion] = $crear();
            try {
                $remision->remitir($this->proceso->fresh(), $this->analista, $necesidad, $solicitud, $designacion);
                $this->fail("Debio exigir el documento: {$caso}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage(), $caso);
            }
            $this->assertSame('PUBLICADO', $this->proceso->fresh()->estadoProceso->codigo, $caso);
            $this->assertSame(0, DB::table('documentos')->whereIn('tipo_documento_id', $tipos)->count(), $caso);
            $this->assertSame([], Storage::disk(ServicioDocumento::DISCO)->allFiles('temporal'), "sin temporales ({$caso})");
        }

        $this->remitirOfertas();
        $this->assertSame('EN_COMISION', $this->proceso->fresh()->estadoProceso->codigo);
        $estados = DB::table('documentos as d')->join('estados_documento as e', 'e.id', '=', 'd.estado_documento_id')
            ->whereIn('d.tipo_documento_id', $tipos)->where('d.proceso_id', $this->proceso->id)->pluck('e.codigo');
        $this->assertCount(3, $estados);
        $this->assertTrue($estados->every(fn ($e) => $e === 'LEGALIZADO'));
    }

    public function test_el_boton_remitir_ofertas_pide_los_tres_pdf_desde_la_pantalla(): void
    {
        $this->registrar($this->tres());
        $this->actingAs($this->analista);

        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionVisible('transicion_remitir_ofertas')
            ->callAction('transicion_remitir_ofertas', ['necesidad' => null, 'memo_solicitud' => null, 'memo_designacion' => null])
            ->assertHasActionErrors(['necesidad' => 'required', 'memo_solicitud' => 'required', 'memo_designacion' => 'required']);
        $this->assertSame('PUBLICADO', $this->proceso->fresh()->estadoProceso->codigo);

        $necesidad = $this->pdfTemporal('necesidad.pdf');
        $solicitud = $this->pdfTemporal('solicitud.pdf');
        $designacion = $this->pdfTemporal('designacion.pdf');
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->callAction('transicion_remitir_ofertas', [
                'necesidad' => [$necesidad[0]], 'nombre_necesidad' => $necesidad[1],
                'memo_solicitud' => [$solicitud[0]], 'nombre_memo_solicitud' => $solicitud[1],
                'memo_designacion' => [$designacion[0]], 'nombre_memo_designacion' => $designacion[1],
            ])
            ->assertHasNoActionErrors();

        $errores = collect(session('filament.notifications', []))->where('status', 'danger')->pluck('body')->all();
        $this->assertSame([], $errores, 'la accion no debio fallar');
        $this->assertSame('EN_COMISION', $this->proceso->fresh()->estadoProceso->codigo);
    }

    public function test_la_seccion_ofertas_muestra_la_publicacion_aunque_no_tenga_ofertas(): void
    {
        $this->actingAs($this->analista);
        $ver = fn () => Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()]);

        // aun no se registran las ofertas de la publicacion: no hay leyenda
        $ver()->assertDontSee('Publicación N.° 1 — 0 ofertas');

        $this->registrar([]);
        $ver()->assertSee('Ofertas')->assertSee('Publicación N.° 1 — 0 ofertas');

        // una segunda publicacion con una oferta: cada grupo con su conteo
        $this->publicar('NC-2026-002');
        $this->registrar([$this->oferta('1790000000001', 'Proveedor A', 1, 1)]);
        $ver()->assertSeeInOrder(['Publicación N.° 1 — 0 ofertas', 'Publicación N.° 2 — 1 oferta', 'Proveedor A']);
    }

    public function test_aviso_de_designacion_segun_el_mayor_monto_total(): void
    {
        $this->registrar($this->tres());
        $remision = app(\App\Services\ServicioRemisionOfertas::class);
        $proceso = $this->proceso->fresh();

        $this->assertTrue($remision->puedeVerAviso($proceso, $this->analista));
        $this->assertFalse($remision->puedeVerAviso($proceso, $this->unidad));
        $d = $remision->designacionSugerida($proceso);
        $this->assertSame('DELEGADO', $d['codigo'], 'montos bajos: Servidor Afin');

        $umbrales = [99999.99 => 'DELEGADO', 100000 => 'COMISION', 499999.99 => 'COMISION', 500000.01 => 'COMISION_FINANCIERO_JURIDICO'];
        foreach ($umbrales as $monto => $esperado) {
            $this->assertSame($esperado, DB::selectOne('select td.codigo from tipos_designacion td where td.id = tipo_designacion_sugerido(?)', [$monto])->codigo, (string) $monto);
        }

        $this->actingAs($this->analista);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertSee('Designación sugerida')
            ->assertSee('Servidor Afín');
        $this->actingAs($this->unidad);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertDontSee('Designación sugerida');
    }

    public function test_los_documentos_fijos_no_se_muestran_mientras_el_proceso_esta_en_la_unidad(): void
    {
        $fijos = app(ServicioDocumentoFijo::class);
        $this->assertTrue($fijos->estadoPermiteVer($this->proceso->fresh()));

        // el estado solo cambia por transicion: se habilita el cambio directo unicamente para esta comprobacion
        DB::select("select set_config('app.transicion_en_curso', 'procesos', false)");
        foreach (['PARA_REPUBLICAR' => false, 'PARA_LEGALIZAR' => false, 'EN_COMISION' => true, 'LEGALIZADO' => true] as $estado => $visible) {
            DB::table('procesos')->where('id', $this->proceso->id)
                ->update(['estado_proceso_id' => DB::table('estados_proceso')->where('codigo', $estado)->value('id')]);
            $this->assertSame($visible, $fijos->estadoPermiteVer($this->proceso->fresh()), $estado);
        }
        DB::select("select set_config('app.transicion_en_curso', '', false)");
    }

    // ---- archivos de la oferta y valor unitario en la ficha ----

    public function test_cada_oferta_exige_sus_archivos_y_no_deja_nada_a_medias(): void
    {
        // cada caso crea sus propios temporales justo antes de ejecutarse
        $casos = [
            'sin archivos' => [fn () => $this->oferta('1790000000001', 'A', 1, 1, []), 'cargue los archivos de la oferta'],
            'formato' => [fn () => $this->oferta('1790000000002', 'B', 1, 1, [$this->pdfTemporal('programa.exe', 'MZ')]), 'no es de un tipo permitido'],
            'vacio' => [fn () => $this->oferta('1790000000003', 'C', 1, 1, [$this->pdfTemporal('vacio.pdf', '')]), 'está vacío'],
        ];

        foreach ($casos as $caso => [$crear, $mensaje]) {
            $oferta = $crear();
            // la primera oferta es valida: debe revertirse junto con la rota
            $buena = $this->oferta('1790000000009', 'Buena', 1, 1);
            try {
                $this->registrar([$buena, $oferta]);
                $this->fail("Debio rechazar: {$caso}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage(), $caso);
            }
            $this->assertSame(0, DB::table('ofertas')->count(), $caso);
            $this->assertSame(0, DB::table('oferta_archivos')->count(), $caso);
            $this->assertSame([], Storage::disk(ServicioDocumento::DISCO)->allFiles('temporal'), "no quedan temporales ({$caso})");
            $this->assertSame([], Storage::disk(ServicioDocumento::DISCO)->allFiles("procesos/{$this->proceso->id}/ofertas"), "no quedan archivos huerfanos ({$caso})");
        }
    }

    public function test_una_oferta_guarda_varios_archivos_con_su_nombre_original(): void
    {
        $oferta = $this->oferta('1790000000001', 'Farma Uno S.A.', 1.5, 2.25, [
            $this->pdfTemporal('Propuesta economica.pdf'),
            $this->pdfTemporal('Anexos.xlsx', 'excel'),
            $this->pdfTemporal('Carpeta completa.zip', 'PK'),
        ]);
        $this->registrar([$oferta]);

        $resumen = $this->ofertas->resumen($this->proceso->fresh())->first();
        $this->assertSame(['Propuesta economica.pdf', 'Anexos.xlsx', 'Carpeta completa.zip'], array_column($resumen['archivos'], 'nombre'));
        $archivo = \App\Models\Archivo::findOrFail($resumen['archivos'][0]['id']);
        $this->assertStringStartsWith("procesos/{$this->proceso->id}/ofertas/{$resumen['id']}/", $archivo->ruta);
        Storage::disk(ServicioDocumento::DISCO)->assertExists($archivo->ruta);
        $this->assertSame([], Storage::disk(ServicioDocumento::DISCO)->allFiles('temporal'), 'los temporales se movieron');
    }

    public function test_la_ficha_muestra_el_valor_unitario_antes_del_total_del_item(): void
    {
        $this->registrar([$this->oferta('1790000000001', 'Farma Uno S.A.', 1.5, 2.25)]);

        $this->actingAs($this->analista);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertSeeInOrder(['Publicación N.° 1', 'RUC / Cédula', 'Razón Social', 'Detalle por ítem', 'Ítem', 'Cantidad', 'Valor unitario', 'Total del ítem'])
            ->assertSeeInOrder(['$ 1.5000', '$ 15.00', '$ 2.2500', '$ 9.00', 'Total del proceso', '$ 24.00', 'Oferta del proveedor', 'Descargar oferta (ZIP)'])
            ->assertDontSee('oferta_1790000000001.pdf');
    }

    public function test_la_ficha_agrupa_las_ofertas_por_numero_de_publicacion(): void
    {
        $this->registrar([$this->oferta('1790000000001', 'Proveedor A', 1, 1)]);
        $this->publicar('NC-2026-002');
        $this->registrar([$this->oferta('1790000000002', 'Proveedor B', 1, 1), $this->oferta('1790000000003', 'Proveedor C', 1, 1)]);

        $this->actingAs($this->analista);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertSeeInOrder(['Publicación N.° 1 — 1 oferta', 'Proveedor A', 'Publicación N.° 2 — 2 ofertas', 'Proveedor B', 'Proveedor C']);
    }

    public function test_la_oferta_se_descarga_completa_en_un_zip(): void
    {
        $oferta = $this->oferta('1790000000001', 'Farma Uno S.A.', 1.5, 2.25);
        $oferta['archivos'] = [$this->pdfTemporal('Propuesta.pdf'), $this->pdfTemporal('Propuesta.pdf'), $this->pdfTemporal('Anexos.xlsx', 'excel')];
        $this->registrar([$oferta]);
        $id = $this->ofertas->resumen($this->proceso->fresh())->first()['id'];
        $url = route('ofertas.descargar_zip', $id);

        $this->get($url)->assertRedirect();
        $this->actingAs(User::factory()->conRoles('UNIDAD_REQUIRENTE')->create())->get($url)->assertForbidden();

        $respuesta = $this->actingAs($this->analista)->get($url);
        $respuesta->assertOk();
        $this->assertStringContainsString('oferta_1790000000001_farma_uno_sa.zip', $respuesta->headers->get('content-disposition'));

        $ruta = tempnam(sys_get_temp_dir(), 'tz');
        file_put_contents($ruta, $respuesta->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($ruta);
        sort($nombres);
        $this->assertSame(['Anexos.xlsx', 'Propuesta.pdf', 'Propuesta_2.pdf'], $nombres);
        $this->assertDatabaseHas('auditoria', ['evento' => 'oferta.zip_descargado', 'entidad_id' => $id]);

        $this->actingAs($this->analista);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])->assertSee('Descargar oferta (ZIP)');
    }
}
