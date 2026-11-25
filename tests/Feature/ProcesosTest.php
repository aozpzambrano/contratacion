<?php

namespace Tests\Feature;

use App\Filament\Resources\Procesos\Pages\CreateProceso;
use App\Models\EstadoProceso;
use App\Models\Proceso;
use App\Models\TipoProceso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreaProcesos;
use Tests\TestCase;

class ProcesosTest extends TestCase
{
    use RefreshDatabase;
    use CreaProcesos;

    public function test_la_unidad_requirente_crea_un_proceso_con_items(): void
    {
        $unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $this->actingAs($unidad);

        Livewire::test(CreateProceso::class)
            ->fillForm([
                'tipo_proceso_id' => TipoProceso::value('id'),
                'objeto_contratacion' => 'Adquisicion de medicamentos',
                'items' => [['cum' => '12345-1', 'cantidad' => 10, 'descripcion' => 'Paracetamol 500 mg']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $p = Proceso::firstOrFail();
        $this->assertSame('INICIO', $p->estadoProceso->codigo);
        $this->assertSame('PREPARATORIA', $p->estadoMacro->codigo);
        $this->assertSame($unidad->id, $p->creado_por);
        $this->assertNull($p->codigo);
        $this->assertSame(1, $p->historial()->count());
        $this->assertSame(1, $p->items()->count());
        $this->assertSame(1, $p->items()->first()->numero_linea);
        $this->assertSame(1, $p->asignaciones()->where('user_id', $unidad->id)->where('activo', true)->count());
    }

    public function test_el_analista_de_compras_no_puede_crear_procesos(): void
    {
        $this->actingAs(User::factory()->conRoles('COMPRAS_ANALISTA')->create());

        $this->get('/admin/procesos/create')->assertForbidden();
    }

    public function test_visibilidad_por_rol_y_asignacion(): void
    {
        $jefe = User::factory()->conRoles('COMPRAS_JEFE')->create();
        $analista1 = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $analista2 = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $unidad1 = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $unidad2 = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $consulta = User::factory()->conRoles('CONSULTA')->create();

        $p1 = $this->proceso($unidad1);
        $this->proceso($unidad2);
        $this->asignar($p1, $analista1, 'COMPRAS_ANALISTA', $jefe);

        $this->assertSame(2, Proceso::visiblesPara($jefe)->count());
        $this->assertSame(2, Proceso::visiblesPara($consulta)->count());
        $this->assertSame(1, Proceso::visiblesPara($analista1)->count());
        $this->assertSame(0, Proceso::visiblesPara($analista2)->count());
        $this->assertSame([$p1->id], Proceso::visiblesPara($unidad1)->pluck('id')->all());
        $this->assertSame(1, Proceso::visiblesPara($unidad2)->count());
    }

    public function test_un_proceso_ajeno_no_es_accesible(): void
    {
        $unidad1 = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $unidad2 = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $p1 = $this->proceso($unidad1);

        $this->actingAs($unidad1)->get('/admin/procesos/'.$p1->id)->assertOk();
        $this->actingAs($unidad2)->get('/admin/procesos/'.$p1->id)->assertNotFound();
    }

    public function test_la_edicion_se_bloquea_al_enviar_a_revision(): void
    {
        $unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $this->actingAs($unidad);

        Livewire::test(CreateProceso::class)
            ->fillForm([
                'tipo_proceso_id' => TipoProceso::value('id'),
                'objeto_contratacion' => 'Proceso a enviar',
                'items' => [['cum' => '1', 'cantidad' => 1, 'descripcion' => 'x']],
            ])
            ->call('create');

        $p = Proceso::firstOrFail();
        $this->assertTrue($unidad->can('update', $p->fresh()));

        $this->cargarDocumento($p, 'INFORME_NECESIDAD', $unidad);
        $this->cargarDocumento($p, 'ESPECIFICACIONES_TECNICAS', $unidad);
        DB::select('select aplicar_transicion_proceso(?, ?, ?, ?)', [$p->id, 'enviar_a_revision', $unidad->id, null]);

        $p = $p->fresh();
        $this->assertSame('EN_REVISION', $p->estadoProceso->codigo);
        $this->assertSame(2, $p->historial()->count());
        $this->assertFalse($unidad->can('update', $p));
    }

    public function test_enviar_a_revision_exige_informe_y_especificaciones_con_archivo(): void
    {
        $unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $p = $this->proceso($unidad);
        $this->asignar($p, $unidad, 'UNIDAD_REQUIRENTE', $unidad);

        $enviar = fn () => DB::select('select aplicar_transicion_proceso(?, ?, ?, ?)', [$p->id, 'enviar_a_revision', $unidad->id, null]);

        try {
            $enviar();
            $this->fail('Debio exigir los documentos');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('Faltan documentos requeridos', $e->getMessage());
        }
    }

    public function test_el_estado_no_se_puede_cambiar_por_update_directo(): void
    {
        $unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $p = $this->proceso($unidad);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('procesos')->where('id', $p->id)
            ->update(['estado_proceso_id' => EstadoProceso::where('codigo', 'PUBLICADO')->value('id')]);
    }
}
