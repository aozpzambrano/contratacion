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
use App\Services\ServicioRemisionDocumentacion;
use App\Services\ServicioPublicacion;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    private function oferta(string $id, string $razon, float $p1, float $p2): array
    {
        return ['identificacion' => $id, 'razon_social' => $razon, 'precios' => [$this->items[1] => $p1, $this->items[2] => $p2]];
    }

    private function registrar(array $ofertas, ?User $usuario = null): void
    {
        $this->ofertas->registrar($this->proceso->fresh(), $usuario ?? $this->analista, $ofertas);
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
                ]],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, $this->ofertas->cantidad($this->proceso->fresh()));
        $this->assertSame(24.0, $this->ofertas->resumen($this->proceso->fresh())->first()['total']);

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

    public function test_remitir_ofertas_devuelve_a_la_unidad_y_esta_remite_estudio_de_mercado_y_poa_a_compras(): void
    {
        $this->registrar($this->tres());
        $this->procesos->aplicarTransicion($this->proceso->fresh(), TransicionProceso::where('codigo', 'remitir_ofertas')->firstOrFail(), $this->analista);

        $proceso = $this->proceso->fresh();
        $this->assertSame('PARA_DOCUMENTACION', $proceso->estadoProceso->codigo);
        $this->assertSame('PREPARATORIA', $proceso->estadoMacro->codigo, 'la fase Precontractual empieza con la comision tecnica');

        $remision = app(ServicioRemisionDocumentacion::class);
        $this->assertTrue($remision->puedeRemitir($proceso, $this->unidad));
        $this->assertFalse($remision->puedeRemitir($proceso, $this->analista));

        // falta un archivo: no cambia nada
        try {
            $remision->remitir($proceso, $this->unidad, $this->pdfTemporal('estudio.pdf'), []);
            $this->fail('Debio exigir la Certificacion POA');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Certificación POA', $e->getMessage());
        }
        $this->assertSame('PARA_DOCUMENTACION', $this->proceso->fresh()->estadoProceso->codigo);
        $this->assertSame(0, DB::table('documentos')->whereIn('tipo_documento_id', TipoDocumento::whereIn('codigo', ['ESTUDIO_MERCADO', 'CERTIFICACION_POA'])->pluck('id'))->count());

        $remision->remitir($proceso, $this->unidad, $this->pdfTemporal('estudio.pdf'), $this->pdfTemporal('poa.pdf'));

        $proceso = $this->proceso->fresh();
        $this->assertSame('PARA_COMISION', $proceso->estadoProceso->codigo);
        $estados = DB::table('documentos as d')->join('estados_documento as e', 'e.id', '=', 'd.estado_documento_id')
            ->join('tipos_documento as t', 't.id', '=', 'd.tipo_documento_id')
            ->whereIn('t.codigo', ['ESTUDIO_MERCADO', 'CERTIFICACION_POA'])->where('d.proceso_id', $proceso->id)->pluck('e.codigo');
        $this->assertCount(2, $estados);
        $this->assertSame(['LEGALIZADO'], $estados->unique()->values()->all());
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
        $this->remitirLegalizados($proceso, $this->unidad, [12], 'AIL-GER-2026-002-M');

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

    public function test_la_unidad_ve_remitir_documentacion_y_compras_no(): void
    {
        $this->registrar($this->tres());
        $this->procesos->aplicarTransicion($this->proceso->fresh(), TransicionProceso::where('codigo', 'remitir_ofertas')->firstOrFail(), $this->analista);

        $this->actingAs($this->unidad);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionVisible('transicion_remitir_documentacion');

        $this->actingAs($this->analista);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionDoesNotExist('transicion_remitir_documentacion');
    }

    public function test_los_documentos_fijos_no_se_muestran_mientras_el_proceso_esta_en_la_unidad(): void
    {
        $fijos = app(ServicioDocumentoFijo::class);
        $this->assertTrue($fijos->estadoPermiteVer($this->proceso->fresh()));

        // el estado solo cambia por transicion: se habilita el cambio directo unicamente para esta comprobacion
        DB::select("select set_config('app.transicion_en_curso', 'procesos', false)");
        foreach (['PARA_REPUBLICAR' => false, 'PARA_LEGALIZAR' => false, 'PARA_DOCUMENTACION' => true, 'LEGALIZADO' => true] as $estado => $visible) {
            DB::table('procesos')->where('id', $this->proceso->id)
                ->update(['estado_proceso_id' => DB::table('estados_proceso')->where('codigo', $estado)->value('id')]);
            $this->assertSame($visible, $fijos->estadoPermiteVer($this->proceso->fresh()), $estado);
        }
        DB::select("select set_config('app.transicion_en_curso', '', false)");
    }
}
