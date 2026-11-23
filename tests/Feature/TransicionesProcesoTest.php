<?php

namespace Tests\Feature;

use App\Filament\Resources\Procesos\Pages\ViewProceso;
use App\Models\Proceso;
use App\Models\TransicionProceso;
use App\Models\User;
use App\Services\ServicioProceso;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreaProcesos;
use Tests\TestCase;

class TransicionesProcesoTest extends TestCase
{
    use CreaProcesos;
    use RefreshDatabase;

    private ServicioProceso $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->servicio = app(ServicioProceso::class);
    }

    private function transicion(string $codigo): TransicionProceso
    {
        return TransicionProceso::where('codigo', $codigo)->firstOrFail();
    }

    /** Proceso en INICIO, con la unidad requirente asignada y los dos documentos requeridos cargados. */
    private function procesoListoParaEnviar(User $unidad): Proceso
    {
        $p = $this->proceso($unidad);
        $this->asignar($p, $unidad, 'UNIDAD_REQUIRENTE', $unidad);
        $this->cargarDocumento($p, 'INFORME_NECESIDAD', $unidad);
        $this->cargarDocumento($p, 'ESPECIFICACIONES_TECNICAS', $unidad);

        return $p;
    }

    public function test_cada_rol_ve_solo_las_transiciones_que_le_corresponden(): void
    {
        $unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $analista = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $p = $this->procesoListoParaEnviar($unidad);
        $this->asignar($p, $analista, 'COMPRAS_ANALISTA', $unidad);

        $this->assertSame(['enviar_a_revision'], $this->servicio->transicionesDisponibles($p, $unidad)->pluck('codigo')->all());
        $this->assertSame([], $this->servicio->transicionesDisponibles($p, $analista)->pluck('codigo')->all());
    }

    public function test_la_unidad_requirente_envia_a_revision_desde_la_pantalla(): void
    {
        $unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $p = $this->procesoListoParaEnviar($unidad);
        $this->actingAs($unidad);

        Livewire::test(ViewProceso::class, ['record' => $p->getRouteKey()])
            ->callAction('transicion_enviar_a_revision');

        $this->assertSame('EN_REVISION', $p->fresh()->estadoProceso->codigo);
        $this->assertSame(2, $p->historial()->count());
    }

    public function test_sin_documentos_el_servicio_devuelve_el_mensaje_de_la_base(): void
    {
        $unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $p = $this->proceso($unidad);
        $this->asignar($p, $unidad, 'UNIDAD_REQUIRENTE', $unidad);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Faltan documentos requeridos');
        $this->servicio->aplicarTransicion($p, $this->transicion('enviar_a_revision'), $unidad);
    }

    public function test_solicitar_correcciones_exige_comentario(): void
    {
        $unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $analista = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $p = $this->procesoListoParaEnviar($unidad);
        $this->asignar($p, $analista, 'COMPRAS_ANALISTA', $unidad);
        $this->servicio->aplicarTransicion($p, $this->transicion('enviar_a_revision'), $unidad);

        $p = $p->fresh();
        $solicitar = $this->transicion('solicitar_correcciones');

        try {
            $this->servicio->aplicarTransicion($p, $solicitar, $analista);
            $this->fail('Debio exigir el comentario');
        } catch (DomainException $e) {
            $this->assertStringContainsString('exige un comentario', $e->getMessage());
        }

        $this->servicio->aplicarTransicion($p, $solicitar, $analista, 'Falta firmar el informe');
        $p = $p->fresh();
        $this->assertSame('EN_CORRECCION', $p->estadoProceso->codigo);
        $this->assertTrue($p->esEditable());
    }

    public function test_el_jefe_asigna_y_reemplaza_al_analista(): void
    {
        $jefe = User::factory()->conRoles('COMPRAS_JEFE')->create();
        $unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $ana1 = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $ana2 = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $p = $this->procesoListoParaEnviar($unidad);
        $this->servicio->aplicarTransicion($p, $this->transicion('enviar_a_revision'), $unidad);
        $p = $p->fresh();

        $this->assertSame(0, Proceso::visiblesPara($ana1)->count());

        $this->servicio->asignarAnalista($p, $ana1, $jefe);
        $this->assertSame(1, Proceso::visiblesPara($ana1)->count());

        $this->servicio->asignarAnalista($p, $ana2, $jefe);
        $this->assertSame(0, Proceso::visiblesPara($ana1)->count());
        $this->assertSame(1, Proceso::visiblesPara($ana2)->count());

        // un solo analista: volver a asignar al primero deja sin acceso al segundo
        $this->servicio->asignarAnalista($p, $ana1, $jefe);
        $this->assertSame(1, Proceso::visiblesPara($ana1)->count());
        $this->assertSame(0, Proceso::visiblesPara($ana2)->count());
    }

    public function test_asignar_analista_solo_despues_de_enviar_a_revision(): void
    {
        $jefe = User::factory()->conRoles('COMPRAS_JEFE')->create();
        $analista = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $p = $this->procesoListoParaEnviar($unidad);

        $this->assertFalse($this->servicio->puedeAsignarAnalista($p, $jefe));
        $this->actingAs($jefe);
        Livewire::test(ViewProceso::class, ['record' => $p->getRouteKey()])->assertActionDoesNotExist('asignar_analista');

        try {
            $this->servicio->asignarAnalista($p, $analista, $jefe);
            $this->fail('No debe asignar antes de enviar a revision');
        } catch (DomainException $e) {
            $this->assertStringContainsString('despues de que la unidad requirente envie', $e->getMessage());
        }

        $this->servicio->aplicarTransicion($p, $this->transicion('enviar_a_revision'), $unidad);
        $this->assertTrue($this->servicio->puedeAsignarAnalista($p->fresh(), $jefe));
        Livewire::test(ViewProceso::class, ['record' => $p->getRouteKey()])->assertActionVisible('asignar_analista');
    }

    public function test_solo_el_jefe_asigna_y_solo_a_analistas(): void
    {
        $jefe = User::factory()->conRoles('COMPRAS_JEFE')->create();
        $analista = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $p = $this->procesoListoParaEnviar($unidad);
        $this->servicio->aplicarTransicion($p, $this->transicion('enviar_a_revision'), $unidad);
        $p = $p->fresh();

        try {
            $this->servicio->asignarAnalista($p, $analista, $analista);
            $this->fail('Un analista no debe poder asignar');
        } catch (DomainException $e) {
            $this->assertStringContainsString('No tiene permiso', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->servicio->asignarAnalista($p, $unidad, $jefe);
    }

    public function test_el_codigo_se_genera_en_para_publicar_y_publicar_lo_exige(): void
    {
        $jefe = User::factory()->conRoles('COMPRAS_JEFE')->create();
        $unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $analista = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $p = $this->procesoListoParaEnviar($unidad);
        $this->asignar($p, $analista, 'COMPRAS_ANALISTA', $jefe);
        $this->asignar($p, $jefe, 'COMPRAS_JEFE', $jefe);

        $this->assertFalse($this->servicio->puedeGenerarCodigo($p->fresh(), $analista));

        $this->servicio->aplicarTransicion($p, $this->transicion('enviar_a_revision'), $unidad);
        $this->servicio->aplicarTransicion($p->fresh(), $this->transicion('validar_documentacion'), $analista);
        $this->servicio->aplicarTransicion($p->fresh(), $this->transicion('autorizar_publicacion'), $jefe);

        $p = $p->fresh();
        $this->assertSame('PARA_PUBLICAR', $p->estadoProceso->codigo);
        $this->assertTrue($this->servicio->puedeGenerarCodigo($p, $analista));
        $this->assertFalse($this->servicio->puedeGenerarCodigo($p, $unidad));

        try {
            $this->servicio->aplicarTransicion($p, $this->transicion('publicar'), $analista);
            $this->fail('Publicar debio exigir el codigo');
        } catch (DomainException $e) {
            $this->assertStringContainsString('debe tener codigo', $e->getMessage());
        }

        $codigo = $this->servicio->generarCodigo($p, $analista);
        $this->assertMatchesRegularExpression('/^PE-AILEP-\d{4}-001$/', $codigo);
        $this->assertSame($codigo, $p->fresh()->codigo);
        $this->assertFalse($this->servicio->puedeGenerarCodigo($p->fresh(), $analista));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ya tiene codigo');
        $this->servicio->generarCodigo($p->fresh(), $analista);
    }
}
