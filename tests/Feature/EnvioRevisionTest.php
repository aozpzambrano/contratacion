<?php

namespace Tests\Feature;

use App\Filament\Resources\Procesos\Pages\ViewProceso;
use App\Filament\Resources\Procesos\RelationManagers\DocumentosRelationManager;
use App\Models\Documento;
use App\Models\Proceso;
use App\Models\TransicionProceso;
use App\Models\User;
use App\Services\ServicioDocumento;
use App\Services\ServicioEnvioRevision;
use App\Services\ServicioProceso;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreaProcesos;
use Tests\TestCase;

/** "Enviar a revision": la unidad carga sus documentos en el propio formulario del boton (primer envio y correcciones). */
class EnvioRevisionTest extends TestCase
{
    use CreaProcesos;
    use RefreshDatabase;

    private ServicioEnvioRevision $envio;

    private User $unidad;

    private Proceso $proceso;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ServicioDocumento::DISCO);
        $this->envio = app(ServicioEnvioRevision::class);
        $this->unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $this->proceso = $this->proceso($this->unidad);
        $this->asignar($this->proceso, $this->unidad, 'UNIDAD_REQUIRENTE', $this->unidad);
    }

    private function archivos(array $claves): array
    {
        $r = [];
        foreach ($claves as $c) {
            $ruta = 'temporal/'.Str::uuid().'.tmp';
            Storage::disk(ServicioDocumento::DISCO)->put($ruta, "%PDF-1.4\n{$c}");
            $r[$c] = [$ruta, $c.'.pdf'];
        }

        return $r;
    }

    private function estado(): string
    {
        return $this->proceso->fresh()->estadoProceso->codigo;
    }

    public function test_el_primer_envio_exige_los_dos_documentos_y_no_deja_nada_a_medias(): void
    {
        $this->assertSame(['informe_necesidad', 'especificaciones_tecnicas'], collect($this->envio->campos($this->proceso))->pluck('clave')->all());

        foreach (['informe_necesidad', 'especificaciones_tecnicas'] as $falta) {
            $archivos = $this->archivos(array_diff(['informe_necesidad', 'especificaciones_tecnicas'], [$falta]));
            try {
                $this->envio->enviar($this->proceso->fresh(), $this->unidad, $archivos);
                $this->fail("Debio exigir {$falta}");
            } catch (DomainException $e) {
                $this->assertStringContainsString('Cargue', $e->getMessage());
            }
            $this->assertSame('INICIO', $this->estado());
            $this->assertSame(0, Documento::count(), 'no se crea ningun documento');
            $this->assertSame([], Storage::disk(ServicioDocumento::DISCO)->allFiles('temporal'), "sin temporales ({$falta})");
        }
    }

    public function test_con_los_dos_documentos_se_cargan_y_el_proceso_pasa_a_revision(): void
    {
        $this->envio->enviar($this->proceso->fresh(), $this->unidad, $this->archivos(['informe_necesidad', 'especificaciones_tecnicas']));

        $this->assertSame('EN_REVISION', $this->estado());
        $this->assertSame(2, Documento::where('proceso_id', $this->proceso->id)->count());
        $this->assertSame(2, DB::table('documentos as d')->join('estados_documento as e', 'e.id', '=', 'd.estado_documento_id')
            ->where('d.proceso_id', $this->proceso->id)->where('e.codigo', 'PENDIENTE_REVISION')->count());
    }

    public function test_solo_la_unidad_puede_enviar(): void
    {
        $this->assertTrue($this->envio->puedeEnviar($this->proceso, $this->unidad));
        $this->assertFalse($this->envio->puedeEnviar($this->proceso, User::factory()->conRoles('COMPRAS_ANALISTA')->create()));
    }

    public function test_la_pantalla_pide_los_dos_archivos_y_en_inicio_no_hay_subir_documento(): void
    {
        $this->actingAs($this->unidad);

        Livewire::test(DocumentosRelationManager::class, ['ownerRecord' => $this->proceso, 'pageClass' => ViewProceso::class])
            ->assertTableActionHidden('subir');

        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionVisible('transicion_enviar_a_revision')
            ->callAction('transicion_enviar_a_revision', ['informe_necesidad' => null, 'especificaciones_tecnicas' => null])
            ->assertHasActionErrors(['informe_necesidad' => 'required', 'especificaciones_tecnicas' => 'required']);
        $this->assertSame('INICIO', $this->estado());

        $a = $this->archivos(['informe_necesidad', 'especificaciones_tecnicas']);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->callAction('transicion_enviar_a_revision', [
                'informe_necesidad' => [$a['informe_necesidad'][0]], 'nombre_informe_necesidad' => $a['informe_necesidad'][1],
                'especificaciones_tecnicas' => [$a['especificaciones_tecnicas'][0]], 'nombre_especificaciones_tecnicas' => $a['especificaciones_tecnicas'][1],
            ])
            ->assertHasNoActionErrors();

        $errores = collect(session('filament.notifications', []))->where('status', 'danger')->pluck('body')->all();
        $this->assertSame([], $errores);
        $this->assertSame('EN_REVISION', $this->estado());
    }

    public function test_en_correccion_el_reenvio_pide_una_version_de_cada_documento_observado(): void
    {
        $this->envio->enviar($this->proceso->fresh(), $this->unidad, $this->archivos(['informe_necesidad', 'especificaciones_tecnicas']));
        $analista = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $jefe = User::factory()->conRoles('COMPRAS_JEFE')->create();
        $procesos = app(ServicioProceso::class);
        $procesos->asignarAnalista($this->proceso->fresh(), $analista, $jefe);
        $informe = Documento::where('proceso_id', $this->proceso->id)->whereHas('tipoDocumento', fn ($q) => $q->where('codigo', 'INFORME_NECESIDAD'))->firstOrFail();
        $procesos->aplicarTransicion($this->proceso->fresh(), TransicionProceso::where('codigo', 'solicitar_correcciones')->firstOrFail(), $analista, 'Falta firma', ['documentos' => [$informe->id]]);
        $this->assertSame('EN_CORRECCION', $this->estado());

        $campos = $this->envio->campos($this->proceso->fresh());
        $this->assertSame(['doc_'.$informe->id], collect($campos)->pluck('clave')->all());
        $this->assertSame('Nueva versión de Informe de necesidad', $campos[0]['etiqueta']);
        // en correccion "Subir documento" solo ofrece "Otros documentos"
        $this->assertSame(['Otros documentos'], app(ServicioDocumento::class)->tiposDisponibles($this->proceso->fresh(), $this->unidad)->values()->all());

        try {
            $this->envio->enviar($this->proceso->fresh(), $this->unidad, []);
            $this->fail('Debio exigir la nueva version');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Cargue', $e->getMessage());
        }
        $this->assertSame('EN_CORRECCION', $this->estado());

        $this->envio->enviar($this->proceso->fresh(), $this->unidad, $this->archivos(['doc_'.$informe->id]));

        $this->assertSame('EN_REVISION', $this->estado());
        $this->assertSame(2, $informe->fresh()->versionActual->numero_version);
        $this->assertSame('PENDIENTE_REVISION', $informe->fresh()->estadoDocumento->codigo);
    }
}
