<?php

namespace Tests\Feature;

use App\Filament\Resources\Procesos\Pages\ViewProceso;
use App\Models\Documento;
use App\Models\Proceso;
use App\Models\TipoDocumento;
use App\Models\TransicionProceso;
use App\Models\User;
use App\Services\ServicioDocumento;
use App\Services\ServicioProceso;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreaProcesos;
use Tests\TestCase;

/** Revision de la documentacion: observaciones, correcciones, validacion y reasignacion del analista. */
class RevisionDocumentosTest extends TestCase
{
    use CreaProcesos;
    use RefreshDatabase;

    private ServicioDocumento $documentos;

    private ServicioProceso $procesos;

    private User $unidad;

    private User $analista;

    private User $jefe;

    private Proceso $proceso;

    private Documento $informe;

    private Documento $especificaciones;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ServicioDocumento::DISCO);
        $this->documentos = app(ServicioDocumento::class);
        $this->procesos = app(ServicioProceso::class);

        $this->unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $this->analista = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $this->jefe = User::factory()->conRoles('COMPRAS_JEFE')->create();

        $p = $this->proceso($this->unidad);
        $this->asignar($p, $this->unidad, 'UNIDAD_REQUIRENTE', $this->unidad);
        $this->informe = $this->subir($p, 'INFORME_NECESIDAD');
        $this->especificaciones = $this->subir($p, 'ESPECIFICACIONES_TECNICAS');

        $this->procesos->aplicarTransicion($p->fresh(), $this->transicion('enviar_a_revision'), $this->unidad);
        $this->procesos->asignarAnalista($p->fresh(), $this->analista, $this->jefe);
        $this->proceso = $p->fresh();
    }

    private function transicion(string $codigo): TransicionProceso
    {
        return TransicionProceso::where('codigo', $codigo)->firstOrFail();
    }

    private function subir(Proceso $p, string $tipo): Documento
    {
        $ruta = 'temporal/'.uniqid('t', true).'.tmp';
        Storage::disk(ServicioDocumento::DISCO)->put($ruta, "%PDF-1.4\n{$tipo}");

        return $this->documentos->crearDocumento(
            $p, TipoDocumento::where('codigo', $tipo)->firstOrFail(), null, $ruta, $tipo.'.pdf', null, $this->unidad
        );
    }

    private function estado(Documento $d): string
    {
        return $d->fresh()->estadoDocumento->codigo;
    }

    private function estadoProceso(): string
    {
        return $this->proceso->fresh()->estadoProceso->codigo;
    }

    /** @param  array<int>|null  $ids  documentos con observaciones (null = todos los que estan en revision) */
    private function remitirObservaciones(?string $comentario = 'Falta la firma del responsable', ?array $ids = null): void
    {
        $this->procesos->aplicarTransicion(
            $this->proceso->fresh(), $this->transicion('solicitar_correcciones'), $this->analista, $comentario, ['documentos' => $ids]
        );
    }

    private function subirVersion(Documento $d, string $nombre): void
    {
        $ruta = 'temporal/'.uniqid('v', true).'.tmp';
        Storage::disk(ServicioDocumento::DISCO)->put($ruta, "%PDF-1.4\n{$nombre}");
        $this->documentos->agregarVersion($d->fresh(), $ruta, $nombre, 'Se agrego la firma', $this->unidad);
    }

    public function test_las_observaciones_se_registran_solo_en_los_documentos_elegidos(): void
    {
        $this->remitirObservaciones('Falta la firma del responsable', [$this->informe->id]);

        $this->assertSame('EN_CORRECCION', $this->estadoProceso());
        $this->assertSame('CON_OBSERVACIONES', $this->estado($this->informe));
        $this->assertSame('PENDIENTE_REVISION', $this->estado($this->especificaciones), 'el no elegido se considera correcto');
        $this->assertSame(1, DB::table('revisiones')->where('resultado', 'con_observaciones')->count());
        $this->assertDatabaseHas('observaciones', ['texto' => 'Falta la firma del responsable', 'user_id' => $this->analista->id]);
        $this->assertSame(0, DB::table('asignaciones_documento')->where('activo', true)->count());
    }

    public function test_sin_documentos_elegidos_no_se_remiten_observaciones_y_el_proceso_no_cambia(): void
    {
        try {
            $this->remitirObservaciones('x', []);
            $this->fail('Debio exigir al menos un documento');
        } catch (DomainException $e) {
            $this->assertStringContainsString('al menos un documento', $e->getMessage());
        }

        $this->assertSame('EN_REVISION', $this->estadoProceso());
        $this->assertSame('PENDIENTE_REVISION', $this->estado($this->informe));
    }

    public function test_el_comentario_es_opcional_y_se_registra_un_texto_generico(): void
    {
        $this->remitirObservaciones(null);

        $this->assertSame('EN_CORRECCION', $this->estadoProceso());
        $this->assertSame(2, DB::table('observaciones')->where('texto', 'like', 'Observaciones remitidas%')->count());
    }

    public function test_en_correccion_la_unidad_carga_versiones_solo_de_los_observados_y_el_analista_no(): void
    {
        $this->remitirObservaciones('Falta la firma', [$this->informe->id]);

        $this->assertTrue($this->documentos->puedeAgregarVersion($this->informe->fresh(), $this->unidad));
        $this->assertFalse($this->documentos->puedeAgregarVersion($this->informe->fresh(), $this->analista));
        // el documento no observado esta en revision y no se corrige
        $this->assertFalse($this->documentos->puedeAgregarVersion($this->especificaciones->fresh(), $this->unidad));
        $this->assertFalse($this->documentos->puedeAgregarVersion($this->especificaciones->fresh(), $this->analista));
    }

    public function test_la_unidad_no_reenvia_sin_version_nueva_del_observado_y_con_ella_queda_la_correccion(): void
    {
        $this->remitirObservaciones('Falta la firma', [$this->informe->id]);
        $reenviar = $this->transicion('reenviar_a_revision');

        try {
            $this->procesos->aplicarTransicion($this->proceso->fresh(), $reenviar, $this->unidad);
            $this->fail('Debio exigir una version nueva');
        } catch (DomainException $e) {
            $this->assertStringContainsString('observaciones pendientes', $e->getMessage());
        }
        $this->assertSame('EN_CORRECCION', $this->estadoProceso());

        $this->subirVersion($this->informe, 'informe_v2.pdf');
        $this->procesos->aplicarTransicion($this->proceso->fresh(), $reenviar, $this->unidad);

        $this->assertSame('EN_REVISION', $this->estadoProceso());
        $this->assertSame('PENDIENTE_REVISION', $this->estado($this->informe));
        $this->assertSame('PENDIENTE_REVISION', $this->estado($this->especificaciones));
        $this->assertSame(1, DB::table('correcciones')->where('descripcion', 'Se agrego la firma')->where('user_id', $this->unidad->id)->count());
    }

    public function test_validar_documentacion_aprueba_los_documentos_y_pasa_a_para_legalizar(): void
    {
        $this->procesos->aplicarTransicion($this->proceso->fresh(), $this->transicion('validar_documentacion'), $this->analista);

        $this->assertSame('PARA_LEGALIZAR', $this->estadoProceso());
        $this->assertSame('APROBADO', $this->estado($this->informe));
        $this->assertSame('APROBADO', $this->estado($this->especificaciones));
        $this->assertSame(2, DB::table('aprobaciones')->where('user_id', $this->analista->id)->count());
        $this->assertSame(2, DB::table('revisiones')->where('resultado', 'aprobada')->count());
    }

    public function test_tras_validar_el_analista_solo_ve_hasta_que_el_jefe_lo_asigne_de_nuevo(): void
    {
        $this->procesos->aplicarTransicion($this->proceso->fresh(), $this->transicion('validar_documentacion'), $this->analista);
        $p = $this->proceso->fresh();

        // sigue viendo el proceso y sus documentos, pero sin acciones
        $this->assertTrue($this->analista->can('view', $p));
        $this->assertSame([], $this->procesos->transicionesDisponibles($p, $this->analista)->pluck('codigo')->all());
        $this->assertFalse($this->documentos->puedeAgregarVersion($this->informe->fresh(), $this->analista));
        $this->assertFalse($this->analista->can('update', $p));

        try {
            $this->procesos->aplicarTransicion($p, $this->transicion('autorizar_publicacion'), $this->analista);
            $this->fail('No debio autorizar sin reasignacion');
        } catch (DomainException $e) {
            $this->assertStringContainsString('asignar nuevamente al analista', $e->getMessage());
        }

        $this->procesos->asignarAnalista($p, $this->analista, $this->jefe);
        $this->assertSame(['autorizar_publicacion'], $this->procesos->transicionesDisponibles($p->fresh(), $this->analista)->pluck('codigo')->all());

        // reasignar al mismo analista en la misma etapa no duplica la asignacion
        $this->procesos->asignarAnalista($p->fresh(), $this->analista, $this->jefe);
        $this->assertSame(1, $p->asignaciones()->where('user_id', $this->analista->id)->where('activo', true)->count());
    }

    public function test_el_analista_remite_observaciones_desde_la_pantalla_con_comentario_opcional(): void
    {
        $this->actingAs($this->analista);

        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionVisible('transicion_solicitar_correcciones')
            ->assertActionVisible('transicion_validar_documentacion')
            ->callAction('transicion_solicitar_correcciones', ['documentos' => [$this->especificaciones->id]])
            ->assertHasNoActionErrors();

        $this->assertSame('CON_OBSERVACIONES', $this->estado($this->especificaciones));
        $this->assertSame('PENDIENTE_REVISION', $this->estado($this->informe));
        $this->assertSame('EN_CORRECCION', $this->estadoProceso());
    }
}
