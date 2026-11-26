<?php

namespace Tests\Feature;

use App\Filament\Resources\Procesos\Pages\ViewProceso;
use App\Filament\Resources\Procesos\RelationManagers\DocumentosRelationManager;
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

class DocumentosTest extends TestCase
{
    use CreaProcesos;
    use RefreshDatabase;

    private ServicioDocumento $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ServicioDocumento::DISCO);
        $this->servicio = app(ServicioDocumento::class);
    }

    private function unidadConProceso(): array
    {
        $unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $p = $this->proceso($unidad);
        $this->asignar($p, $unidad, 'UNIDAD_REQUIRENTE', $unidad);

        return [$unidad, $p];
    }

    /** Deja un archivo en temporal/ como lo hace la interfaz y devuelve su ruta. */
    private function temporal(string $contenido): string
    {
        $ruta = 'temporal/'.uniqid('t', true).'.tmp';
        Storage::disk(ServicioDocumento::DISCO)->put($ruta, $contenido);

        return $ruta;
    }

    private function subir(Proceso $p, User $u, string $tipo, string $nombre = 'informe.pdf', ?string $contenido = null): Documento
    {
        $contenido ??= str_ends_with($nombre, '.pdf') ? "%PDF-1.4\ncontenido de prueba" : 'contenido de prueba';

        return $this->servicio->crearDocumento(
            $p, TipoDocumento::where('codigo', $tipo)->firstOrFail(), null, $this->temporal($contenido), $nombre, 'primera version', $u
        );
    }

    public function test_la_unidad_crea_un_documento_word_en_borrador_con_su_primera_version(): void
    {
        [$unidad, $p] = $this->unidadConProceso();

        $d = $this->subir($p, $unidad, 'INFORME_NECESIDAD', 'informe.docx');

        $this->assertSame('BORRADOR', $d->estadoDocumento->codigo);
        $this->assertSame('informe', $d->titulo, 'el titulo es el nombre del archivo sin extension');
        $this->assertSame(1, $d->versionActual->numero_version);

        $archivo = $d->versionActual->archivo;
        $this->assertSame('informe.docx', $archivo->nombre_original);
        $this->assertSame(hash('sha256', 'contenido de prueba'), $archivo->hash_sha256);
        $this->assertStringStartsWith("procesos/{$p->id}/documentos/{$d->id}/", $archivo->ruta);
        Storage::disk('documentos')->assertExists($archivo->ruta);
        $this->assertSame([], Storage::disk('documentos')->files('temporal'));
        $this->assertDatabaseHas('auditoria', ['evento' => 'documento.creado', 'entidad_id' => $d->id]);
    }

    public function test_rechaza_extension_no_permitida_y_limpia_el_temporal(): void
    {
        [$unidad, $p] = $this->unidadConProceso();

        try {
            $this->subir($p, $unidad, 'INFORME_NECESIDAD', 'virus.exe');
            $this->fail('Debio rechazar la extension');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Tipo de archivo no permitido', $e->getMessage());
        }

        $this->assertSame(0, Documento::count());
        $this->assertSame([], Storage::disk('documentos')->allFiles());
    }

    public function test_rechaza_pdf_falso_archivo_vacio_y_archivo_demasiado_grande(): void
    {
        [$unidad, $p] = $this->unidadConProceso();

        foreach ([
            ['no es pdf', 'informe.pdf', 'no es un PDF valido'],
            ['', 'informe.docx', 'esta vacio'],
        ] as [$contenido, $nombre, $mensaje]) {
            try {
                $this->subir($p, $unidad, 'INFORME_NECESIDAD', $nombre, $contenido);
                $this->fail('Debio rechazar: '.$mensaje);
            } catch (DomainException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }

        // el limite sale del parametro (editable): se baja a 1 MB y se sube un archivo de 2 MB
        DB::table('parametros')->where('clave', 'tamano_maximo_documento_mb')->update(['valor' => '1']);
        try {
            $this->subir($p, $unidad, 'INFORME_NECESIDAD', 'grande.docx', str_repeat('a', 2 * 1024 * 1024));
            $this->fail('Debio rechazar por tamano');
        } catch (DomainException $e) {
            $this->assertStringContainsString('tamano maximo', $e->getMessage());
        }
        $this->assertSame(0, Documento::count());
    }

    public function test_nueva_version_se_numera_y_pasa_a_ser_la_vigente(): void
    {
        [$unidad, $p] = $this->unidadConProceso();
        $d = $this->subir($p, $unidad, 'INFORME_NECESIDAD');

        $d = $this->servicio->agregarVersion($d, $this->temporal("%PDF-1.4\nsegunda"), 'informe_v2.pdf', 'ajustes', $unidad);

        $this->assertSame(2, $d->versionActual->numero_version);
        $this->assertSame(2, $d->versiones()->count());
        $this->assertSame('informe_v2.pdf', $d->versionActual->archivo->nombre_original);
    }

    public function test_no_se_agrega_version_si_el_documento_ya_fue_enviado_a_revision(): void
    {
        [$unidad, $p] = $this->unidadConProceso();
        $d = $this->subir($p, $unidad, 'INFORME_NECESIDAD');
        DB::select('select aplicar_transicion_documento(?, ?, ?)', [$d->id, 'enviar_a_revision', $unidad->id]);

        $this->expectException(DomainException::class);
        $this->servicio->agregarVersion($d->fresh(), $this->temporal("%PDF-1.4\nx"), 'otra.pdf', null, $unidad);
    }

    public function test_otros_documentos_se_habilita_fuera_de_inicio_para_quien_puede_subir(): void
    {
        [$unidad, $p] = $this->unidadConProceso();
        $this->assertFalse($this->servicio->tiposDisponibles($p, $unidad)->contains('Otros documentos'));

        // el estado solo cambia por transicion: se habilita el cambio directo unicamente para esta comprobacion
        DB::select("select set_config('app.transicion_en_curso', 'procesos', false)");
        DB::table('procesos')->where('id', $p->id)
            ->update(['estado_proceso_id' => DB::table('estados_proceso')->where('codigo', 'EN_CORRECCION')->value('id')]);
        DB::select("select set_config('app.transicion_en_curso', '', false)");

        $this->assertTrue($this->servicio->tiposDisponibles($p->fresh(), $unidad)->contains('Otros documentos'));
    }

    public function test_cada_rol_solo_ve_los_tipos_de_su_fase_y_de_su_rol(): void
    {
        [$unidad, $p] = $this->unidadConProceso();

        $tipos = $this->servicio->tiposDisponibles($p, $unidad)->values();
        $this->assertTrue($tipos->contains('Informe de necesidad'));
        $this->assertTrue($tipos->contains('Especificaciones tecnicas'));
        $this->assertFalse($tipos->contains('Orden de compra'));

        // en Inicio la unidad carga solo esos dos tipos: ni "Otros documentos" ni ningun otro
        $this->assertEqualsCanonicalizing(['Informe de necesidad', 'Especificaciones tecnicas'], $tipos->all());

        $otra = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $this->assertTrue($this->servicio->tiposDisponibles($p, $otra)->isEmpty(), 'otra unidad no ve el proceso');

        $this->expectException(DomainException::class);
        $this->servicio->crearDocumento(
            $p, TipoDocumento::where('codigo', 'ORDEN_COMPRA')->firstOrFail(), null,
            $this->temporal("%PDF-1.4\nx"), 'orden.pdf', null, $unidad
        );
    }

    public function test_al_enviar_el_proceso_los_borradores_pasan_a_pendiente_de_revision(): void
    {
        [$unidad, $p] = $this->unidadConProceso();
        $a = $this->subir($p, $unidad, 'INFORME_NECESIDAD');
        $b = $this->subir($p, $unidad, 'ESPECIFICACIONES_TECNICAS', 'especificaciones.xlsx');

        app(ServicioProceso::class)->aplicarTransicion(
            $p->fresh(), TransicionProceso::where('codigo', 'enviar_a_revision')->firstOrFail(), $unidad
        );

        foreach ([$a, $b] as $d) {
            $this->assertSame('PENDIENTE_REVISION', $d->fresh()->estadoDocumento->codigo);
            $this->assertDatabaseHas('historial_estados_documento', ['documento_id' => $d->id, 'user_id' => $unidad->id]);
        }
        $this->assertSame(2, DB::table('historial_estados_documento')->where('user_id', $unidad->id)->whereNotNull('estado_origen_id')->count());
    }

    public function test_si_falta_un_requisito_el_proceso_y_los_documentos_no_cambian(): void
    {
        [$unidad, $p] = $this->unidadConProceso();
        $a = $this->subir($p, $unidad, 'INFORME_NECESIDAD'); // falta Especificaciones tecnicas

        try {
            app(ServicioProceso::class)->aplicarTransicion(
                $p->fresh(), TransicionProceso::where('codigo', 'enviar_a_revision')->firstOrFail(), $unidad
            );
            $this->fail('Debio exigir las especificaciones tecnicas');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Faltan documentos requeridos', $e->getMessage());
        }

        $this->assertSame('BORRADOR', $a->fresh()->estadoDocumento->codigo);
    }

    public function test_despues_de_enviar_la_unidad_ya_no_carga_pero_el_analista_asignado_si(): void
    {
        [$unidad, $p] = $this->unidadConProceso();
        $this->subir($p, $unidad, 'INFORME_NECESIDAD');
        $this->subir($p, $unidad, 'ESPECIFICACIONES_TECNICAS');
        app(ServicioProceso::class)->aplicarTransicion(
            $p->fresh(), TransicionProceso::where('codigo', 'enviar_a_revision')->firstOrFail(), $unidad
        );
        $p = $p->fresh();

        $analista = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $this->assertFalse($this->servicio->puedeSubir($p, $unidad));
        $this->assertFalse($this->servicio->puedeSubir($p, $analista), 'sin asignacion no ve ni carga');

        $this->asignar($p, $analista, 'COMPRAS_ANALISTA', $unidad);
        $this->assertTrue($this->servicio->puedeSubir($p, $analista));
    }

    public function test_descarga_solo_para_quien_tiene_permiso_y_ve_el_proceso(): void
    {
        [$unidad, $p] = $this->unidadConProceso();
        $d = $this->subir($p, $unidad, 'INFORME_NECESIDAD');
        $url = route('documentos.descargar', $d->version_actual_id);

        $this->get($url)->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->conRoles('UNIDAD_REQUIRENTE')->create())->get($url)->assertForbidden();

        $respuesta = $this->actingAs($unidad)->get($url);
        $respuesta->assertOk();
        $this->assertStringContainsString('attachment', $respuesta->headers->get('content-disposition'));
        $this->assertStringContainsString('informe.pdf', $respuesta->headers->get('content-disposition'));
        $this->assertDatabaseHas('auditoria', ['evento' => 'documento.descargado', 'entidad_id' => $d->id, 'user_id' => $unidad->id]);

        $jefe = User::factory()->conRoles('COMPRAS_JEFE')->create();
        $this->actingAs($jefe)->get($url)->assertOk();
    }

    public function test_solo_un_documento_por_tipo_las_correcciones_son_versiones(): void
    {
        [$unidad, $p] = $this->unidadConProceso();
        $this->subir($p, $unidad, 'INFORME_NECESIDAD');

        $this->assertFalse($this->servicio->tiposDisponibles($p, $unidad)->contains('Informe de necesidad'));

        try {
            $this->subir($p, $unidad, 'INFORME_NECESIDAD', 'otro.pdf');
            $this->fail('No debe permitir un segundo informe de necesidad');
        } catch (DomainException $e) {
            $this->assertStringContainsString('use "Nueva version"', $e->getMessage());
        }
        $this->assertSame(1, Documento::count());

        // la base tambien lo impide
        try {
            DB::transaction(fn () => DB::table('documentos')->insert([
                'proceso_id' => $p->id,
                'tipo_documento_id' => TipoDocumento::where('codigo', 'INFORME_NECESIDAD')->value('id'),
                'estado_documento_id' => DB::table('estados_documento')->where('codigo', 'BORRADOR')->value('id'),
                'titulo' => 'duplicado', 'creado_por' => $unidad->id,
            ]));
            $this->fail('La BD debio rechazar el duplicado');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('cargue una nueva version', $e->getMessage());
        }
    }

    public function test_el_memorando_de_publicacion_es_solo_pdf_y_admite_varios(): void
    {
        [$unidad, $p] = $this->unidadConProceso();
        // en Inicio solo se cargan Informe de Necesidad y Especificaciones Tecnicas: el memorando se sube en una etapa posterior
        DB::select("select set_config('app.transicion_en_curso', 'procesos', false)");
        DB::table('procesos')->where('id', $p->id)
            ->update(['estado_proceso_id' => DB::table('estados_proceso')->where('codigo', 'EN_CORRECCION')->value('id')]);
        DB::select("select set_config('app.transicion_en_curso', '', false)");
        $p = $p->fresh();

        $this->subir($p, $unidad, 'MEMORANDO_SOLICITUD_PUBLICACION', 'memo1.pdf');
        $this->subir($p, $unidad, 'MEMORANDO_SOLICITUD_PUBLICACION', 'memo2.pdf');
        $this->assertSame(2, Documento::count());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tipo de archivo no permitido');
        $this->subir($p, $unidad, 'MEMORANDO_SOLICITUD_PUBLICACION', 'memo3.docx');
    }

    public function test_solo_la_ultima_version_se_descarga_las_anteriores_son_respaldo(): void
    {
        [$unidad, $p] = $this->unidadConProceso();
        $d = $this->subir($p, $unidad, 'INFORME_NECESIDAD');
        $v1 = $d->version_actual_id;
        $d = $this->servicio->agregarVersion($d, $this->temporal("%PDF-1.4\nsegunda"), 'v2.pdf', null, $unidad);

        $this->actingAs($unidad);
        $this->get(route('documentos.descargar', $v1))->assertForbidden();
        $this->get(route('documentos.descargar', $d->version_actual_id))->assertOk();
    }

    public function test_el_analista_asignado_sube_version_conservando_el_estado_y_no_edita_el_proceso(): void
    {
        [$unidad, $p] = $this->unidadConProceso();
        $d = $this->subir($p, $unidad, 'INFORME_NECESIDAD');
        $this->subir($p, $unidad, 'ESPECIFICACIONES_TECNICAS');
        app(ServicioProceso::class)->aplicarTransicion(
            $p->fresh(), TransicionProceso::where('codigo', 'enviar_a_revision')->firstOrFail(), $unidad
        );
        $p = $p->fresh();
        $analista = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $otro = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $this->asignar($p, $analista, 'COMPRAS_ANALISTA', $unidad);

        $d = $d->fresh();
        $this->assertSame('PENDIENTE_REVISION', $d->estadoDocumento->codigo);
        $this->assertTrue($this->servicio->puedeAgregarVersion($d, $analista));
        $this->assertFalse($this->servicio->puedeAgregarVersion($d, $unidad), 'la unidad ya no corrige mientras se revisa');
        $this->assertFalse($this->servicio->puedeAgregarVersion($d, $otro), 'otro analista no asignado');

        $d = $this->servicio->agregarVersion($d, $this->temporal("%PDF-1.4\ncorregido"), 'corregido.pdf', 'ajuste de redaccion', $analista);
        $this->assertSame(2, $d->versionActual->numero_version);
        $this->assertSame('PENDIENTE_REVISION', $d->estadoDocumento->codigo, 'el estado se conserva');

        // el analista ve los documentos desde "Ver" y no puede editar el proceso ni los items
        $this->assertTrue($analista->can('view', $p));
        $this->assertFalse($analista->can('update', $p));
        $this->actingAs($analista);
        Livewire::test(DocumentosRelationManager::class, ['ownerRecord' => $p, 'pageClass' => ViewProceso::class])
            ->assertCanSeeTableRecords([$d])
            ->assertTableActionVisible('descargar', $d)
            ->assertTableActionVisible('nueva_version', $d);
        Livewire::test(ViewProceso::class, ['record' => $p->getRouteKey()])->assertActionHidden('edit');
    }

    public function test_al_reenviar_el_proceso_los_documentos_con_observaciones_vuelven_a_revision(): void
    {
        [$unidad, $p] = $this->unidadConProceso();
        $d = $this->subir($p, $unidad, 'INFORME_NECESIDAD');
        $estado = fn (string $codigo) => DB::table('estados_documento')->where('codigo', $codigo)->value('id');

        // se simula el resultado de una revision con observaciones (E2 las registrara con su flujo)
        DB::select("select set_config('app.transicion_en_curso', 'documentos', true)");
        DB::table('documentos')->where('id', $d->id)->update(['estado_documento_id' => $estado('CON_OBSERVACIONES')]);
        DB::select("select set_config('app.transicion_en_curso', '', true)");

        $this->assertSame(1, $this->servicio->enviarDocumentosARevision($p, $unidad));

        $this->assertSame('PENDIENTE_REVISION', $d->fresh()->estadoDocumento->codigo);
        $this->assertSame(0, DB::table('asignaciones_documento')->where('documento_id', $d->id)->where('activo', true)->count());
    }

    public function test_el_gestor_de_documentos_lista_y_muestra_acciones_segun_permisos(): void
    {
        [$unidad, $p] = $this->unidadConProceso();
        $d = $this->subir($p, $unidad, 'INFORME_NECESIDAD');
        $this->actingAs($unidad);

        Livewire::test(DocumentosRelationManager::class, ['ownerRecord' => $p, 'pageClass' => ViewProceso::class])
            ->assertCanSeeTableRecords([$d])
            ->assertTableActionVisible('descargar', $d)
            ->assertTableActionVisible('nueva_version', $d);

        $jefe = User::factory()->conRoles('COMPRAS_JEFE')->create();
        $this->actingAs($jefe);
        Livewire::test(DocumentosRelationManager::class, ['ownerRecord' => $p, 'pageClass' => ViewProceso::class])
            ->assertCanSeeTableRecords([$d])
            ->assertTableActionHidden('nueva_version', $d);
    }

    public function test_el_selector_de_archivos_se_limita_a_los_formatos_del_tipo(): void
    {
        $memorando = TipoDocumento::where('codigo', 'MEMORANDO_SOLICITUD_PUBLICACION')->firstOrFail();
        $this->assertSame(['application/pdf'], $memorando->mimesPermitidos());
        $this->assertSame('PDF', $memorando->formatosLegibles());

        $distributivo = TipoDocumento::where('codigo', 'DISTRIBUTIVO')->firstOrFail();
        $this->assertEqualsCanonicalizing([
            'application/pdf', 'application/msword', 'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], $distributivo->mimesPermitidos());
        $this->assertSame('PDF, Word o Excel', $distributivo->formatosLegibles());
    }

    public function test_el_titulo_es_el_nombre_del_archivo_o_el_digitado_o_el_del_tipo(): void
    {
        $tipo = TipoDocumento::where('codigo', 'INFORME_NECESIDAD')->firstOrFail();

        $this->assertSame('Informe final 2026', $this->servicio->tituloDelDocumento($tipo, null, 'Informe final 2026.pdf'));
        $this->assertSame('Mi titulo', $this->servicio->tituloDelDocumento($tipo, '  Mi titulo ', 'otro.pdf'), 'lo digitado se respeta');
        $this->assertSame('Informe de necesidad', $this->servicio->tituloDelDocumento($tipo, null, '.pdf'), 'sin nombre util: el del tipo');
        $this->assertSame('Informe de necesidad', $this->servicio->tituloDelDocumento($tipo, '', ''));
        $this->assertSame('Informe de necesidad', $this->servicio->tituloDelDocumento($tipo, null, "\xC3\x28.pdf"), 'nombre mal codificado: el del tipo');
        $this->assertSame(250, mb_strlen($this->servicio->tituloDelDocumento($tipo, null, str_repeat('a', 400).'.pdf')));

        // varios archivos a la vez: cada documento toma el nombre de su archivo
        [$unidad, $p] = $this->unidadConProceso();
        $a = $this->subir($p, $unidad, 'ESPECIFICACIONES_TECNICAS', 'especificaciones_v1.pdf');
        $this->assertSame('especificaciones_v1', $a->titulo);
    }
}
