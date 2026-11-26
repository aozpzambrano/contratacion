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
use App\Services\ServicioLegalizacion;
use App\Services\ServicioProceso;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreaProcesos;
use Tests\TestCase;

/** "Remitir documentos legalizados": PDF firmados, memorando, solicitud de publicacion y devolucion a la unidad. */
class LegalizacionTest extends TestCase
{
    use CreaProcesos;
    use RefreshDatabase;

    private ServicioProceso $procesos;

    private ServicioLegalizacion $legalizacion;

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
        $this->procesos = app(ServicioProceso::class);
        $this->legalizacion = app(ServicioLegalizacion::class);

        $this->unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $this->analista = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $this->jefe = User::factory()->conRoles('COMPRAS_JEFE')->create();

        $p = $this->proceso($this->unidad);
        $this->asignar($p, $this->unidad, 'UNIDAD_REQUIRENTE', $this->unidad);
        $this->informe = $this->subir($p, 'INFORME_NECESIDAD');
        $this->especificaciones = $this->subir($p, 'ESPECIFICACIONES_TECNICAS');

        $this->procesos->aplicarTransicion($p->fresh(), $this->transicion('enviar_a_revision'), $this->unidad);
        $this->procesos->asignarAnalista($p->fresh(), $this->analista, $this->jefe);
        $this->procesos->aplicarTransicion($p->fresh(), $this->transicion('validar_documentacion'), $this->analista);
        $this->proceso = $p->fresh();
    }

    private function transicion(string $codigo): TransicionProceso
    {
        return TransicionProceso::where('codigo', $codigo)->firstOrFail();
    }

    private function subir(Proceso $p, string $tipo): Documento
    {
        [$ruta] = $this->pdfTemporal($tipo.'.pdf', "%PDF-1.4\n{$tipo}");

        return app(ServicioDocumento::class)->crearDocumento(
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

    private function codigosDisponibles(User $usuario): array
    {
        return $this->procesos->transicionesDisponibles($this->proceso->fresh(), $usuario)->pluck('codigo')->all();
    }

    /** Archivos que hay en el disco (sin contar los temporales). */
    private function archivosDefinitivos(): array
    {
        return array_values(array_filter(
            Storage::disk(ServicioDocumento::DISCO)->allFiles(),
            fn ($ruta) => ! str_starts_with($ruta, 'temporal/')
        ));
    }

    public function test_la_unidad_remite_los_legalizados_con_memorando_y_horas_por_publicacion(): void
    {
        $this->assertSame('PARA_LEGALIZAR', $this->estadoProceso());
        $this->assertSame(['remitir_legalizados'], $this->codigosDisponibles($this->unidad));

        $this->remitirLegalizados($this->proceso, $this->unidad, [24, 48, 72], 'AIL-GER-2026-045-M');

        // el proceso queda "Legalizado"; los dos documentos quedan legalizados con su PDF firmado
        $this->assertSame('LEGALIZADO', $this->estadoProceso());
        $this->assertSame('LEGALIZADO', $this->estado($this->informe));
        $this->assertSame('LEGALIZADO', $this->estado($this->especificaciones));
        $this->assertSame(2, DB::table('legalizaciones')->whereNotNull('archivo_id')->where('user_id', $this->unidad->id)->count());
        $this->assertSame('informe_necesidad_firmado.pdf', $this->informe->fresh()->legalizacionVigente()->archivo->nombre_original);

        // memorando como documento PDF de la unidad
        $memorando = Documento::where('proceso_id', $this->proceso->id)
            ->whereHas('tipoDocumento', fn ($q) => $q->where('codigo', 'MEMORANDO_SOLICITUD_PUBLICACION'))->firstOrFail();
        $this->assertSame('APROBADO', $this->estado($memorando));
        $this->assertSame('pdf', $memorando->versionActual->archivo->extension);

        // solicitud con numero de memorando y horas de cada publicacion
        $solicitud = $this->proceso->fresh()->solicitudRemitida();
        $this->assertSame('AIL-GER-2026-045-M', $solicitud->numero_memorando);
        $this->assertSame($memorando->id, $solicitud->memorando_documento_id);
        $this->assertSame([24, 48, 72], $solicitud->detalle->pluck('horas')->map(fn ($h) => (int) $h)->all());
        $this->assertSame('1.ª: 24 h (1 día) · 2.ª: 48 h (2 días) · 3.ª: 72 h (3 días)', $solicitud->resumenPublicaciones());
        $this->assertDatabaseHas('auditoria', ['evento' => 'proceso.legalizados_remitidos', 'entidad_id' => $this->proceso->id]);
    }

    public function test_tras_remitir_cambian_las_acciones_disponibles(): void
    {
        $this->assertSame([], $this->codigosDisponibles($this->jefe), 'sin remision no hay nada que devolver');

        $this->remitirLegalizados($this->proceso, $this->unidad);

        $this->assertSame([], $this->codigosDisponibles($this->unidad), 'la unidad no remite dos veces');
        // el analista conserva "ver" hasta que el Jefe lo asigne de nuevo
        $this->assertSame([], $this->codigosDisponibles($this->analista));
        $this->assertSame(['devolver_legalizacion'], $this->codigosDisponibles($this->jefe));

        // al asignar al analista el proceso pasa solo a "Para publicar" y se genera el numero
        $this->procesos->asignarAnalista($this->proceso->fresh(), $this->analista, $this->jefe);
        $this->assertSame('PARA_PUBLICAR', $this->estadoProceso());
        $this->assertMatchesRegularExpression('/^PE-AILEP-\d{4}-\d{3}$/', $this->proceso->fresh()->codigo);
        $this->assertContains('devolver_publicacion', $this->codigosDisponibles($this->analista));
        $this->assertNotContains('autorizar_publicacion', $this->codigosDisponibles($this->analista));
    }

    public function test_los_datos_invalidos_no_dejan_nada_a_medias_y_limpian_los_archivos(): void
    {
        $casos = [
            'horas en cero' => [[0], 'AIL-1', 'múltiplo de 24'],
            'horas excesivas' => [[24, 9000], 'AIL-1', 'múltiplo de 24'],
            'horas no enteras' => [['abc'], 'AIL-1', 'múltiplo de 24'],
            'dia de 25 horas' => [[25], 'AIL-1', 'múltiplo de 24'],
            'una publicacion de 36 horas' => [[24, 36], 'AIL-1', 'múltiplo de 24'],
            'sin publicaciones' => [[], 'AIL-1', 'entre 1 y'],
            'demasiadas publicaciones' => [array_fill(0, 11, 24), 'AIL-1', 'entre 1 y'],
            'memorando vacio' => [[24], '   ', 'número del memorando'],
        ];

        foreach ($casos as $nombre => [$horas, $numero, $mensaje]) {
            try {
                $this->remitirLegalizados($this->proceso, $this->unidad, $horas, $numero);
                $this->fail("Debio rechazar: {$nombre}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage(), $nombre);
            }

            $this->assertSame('APROBADO', $this->estado($this->informe), $nombre);
            $this->assertSame(0, DB::table('solicitudes_publicacion')->count(), $nombre);
            $this->assertSame([], Storage::disk(ServicioDocumento::DISCO)->allFiles('temporal'), "{$nombre}: temporales limpios");
        }
    }

    public function test_solo_se_aceptan_pdf_y_un_pdf_falso_revierte_todo(): void
    {
        $legalizados = [
            $this->informe->id => $this->pdfTemporal('informe_firmado.pdf'),
            $this->especificaciones->id => $this->pdfTemporal('especificaciones_firmadas.docx'),
        ];

        try {
            $this->legalizacion->remitir($this->proceso, $this->unidad, $legalizados, $this->pdfTemporal('memorando.pdf'), 'AIL-2', [24]);
            $this->fail('Debio rechazar un archivo que no es PDF');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Formatos aceptados: PDF', $e->getMessage());
        }

        // el primero ya se habia legalizado dentro de la transaccion: debe haberse revertido, con sus archivos
        $this->assertSame('APROBADO', $this->estado($this->informe));
        $this->assertSame(0, DB::table('legalizaciones')->count());
        $this->assertSame(0, DB::table('solicitudes_publicacion')->count());
        $this->assertCount(2, $this->archivosDefinitivos(), 'solo los archivos de los borradores originales');
        $this->assertSame([], Storage::disk(ServicioDocumento::DISCO)->allFiles('temporal'));

        // PDF con contenido que no es PDF
        $legalizados = [
            $this->informe->id => $this->pdfTemporal('a.pdf', 'no soy un pdf'),
            $this->especificaciones->id => $this->pdfTemporal('b.pdf'),
        ];
        try {
            $this->legalizacion->remitir($this->proceso, $this->unidad, $legalizados, $this->pdfTemporal('memorando.pdf'), 'AIL-2', [24]);
            $this->fail('Debio rechazar un PDF falso');
        } catch (DomainException $e) {
            $this->assertStringContainsString('no es un PDF valido', $e->getMessage());
        }
        $this->assertSame('APROBADO', $this->estado($this->informe));
    }

    public function test_falta_algun_pdf_legalizado_o_el_memorando(): void
    {
        $legalizados = [$this->informe->id => $this->pdfTemporal('informe_firmado.pdf')];
        try {
            $this->legalizacion->remitir($this->proceso, $this->unidad, $legalizados, $this->pdfTemporal('memorando.pdf'), 'AIL-3', [24]);
            $this->fail('Debio exigir el PDF de las especificaciones');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Cargue el PDF legalizado', $e->getMessage());
        }

        $legalizados = [
            $this->informe->id => $this->pdfTemporal('i.pdf'),
            $this->especificaciones->id => $this->pdfTemporal('e.pdf'),
        ];
        try {
            $this->legalizacion->remitir($this->proceso, $this->unidad, $legalizados, ['', ''], 'AIL-3', [24]);
            $this->fail('Debio exigir el memorando');
        } catch (DomainException $e) {
            $this->assertStringContainsString('memorando de solicitud', $e->getMessage());
        }
        $this->assertSame('APROBADO', $this->estado($this->informe));
    }

    public function test_faltan_los_documentos_adicionales_y_la_base_dice_cuales(): void
    {
        $this->assertSame(5, $this->legalizacion->tiposAdicionalesPendientes($this->proceso)->count());
        $legalizados = [
            $this->informe->id => $this->pdfTemporal('i.pdf'),
            $this->especificaciones->id => $this->pdfTemporal('e.pdf'),
        ];
        $fichas = \App\Models\TipoDocumento::where('codigo', 'FICHAS_TECNICAS')->value('id');

        try {
            $this->legalizacion->remitir(
                $this->proceso, $this->unidad, $legalizados, $this->pdfTemporal('m.pdf'), 'AIL-7', [24],
                [$fichas => [$this->pdfTemporal('f1.pdf'), $this->pdfTemporal('f2.pdf')]]
            );
            $this->fail('Debio exigir los demas documentos');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Faltan documentos requeridos', $e->getMessage());
            $this->assertStringContainsString('Certificado de no catalogado', $e->getMessage());
            $this->assertStringContainsString('Autorizacion del requerimiento', $e->getMessage());
            $this->assertStringNotContainsString('Fichas tecnicas', $e->getMessage());
        }

        // nada queda a medias: ni documentos, ni solicitud, ni archivos
        $this->assertSame('APROBADO', $this->estado($this->informe));
        $this->assertSame(0, DB::table('solicitudes_publicacion')->count());
        $this->assertSame(0, Documento::where('proceso_id', $this->proceso->id)->whereHas('tipoDocumento', fn ($q) => $q->where('codigo', 'FICHAS_TECNICAS'))->count());
        $this->assertCount(2, $this->archivosDefinitivos());
        $this->assertSame([], Storage::disk(ServicioDocumento::DISCO)->allFiles('temporal'));
    }

    public function test_solo_la_unidad_asignada_puede_remitir(): void
    {
        foreach ([$this->analista, $this->jefe, User::factory()->conRoles('UNIDAD_REQUIRENTE')->create()] as $otro) {
            try {
                $this->legalizacion->remitir(
                    $this->proceso, $otro,
                    [$this->informe->id => $this->pdfTemporal('i.pdf'), $this->especificaciones->id => $this->pdfTemporal('e.pdf')],
                    $this->pdfTemporal('m.pdf'), 'AIL-4', [24]
                );
                $this->fail('No debio permitir remitir a este usuario');
            } catch (DomainException $e) {
                $this->assertStringContainsString('No puede remitir', $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('solicitudes_publicacion')->count());
        $this->assertSame([], Storage::disk(ServicioDocumento::DISCO)->allFiles('temporal'));
    }

    /** Documentos del proceso (no anulados) del tipo dado. */
    private function documentosDe(string $codigoTipo)
    {
        return Documento::where('proceso_id', $this->proceso->id)
            ->whereHas('tipoDocumento', fn ($q) => $q->where('codigo', $codigoTipo))
            ->whereHas('estadoDocumento', fn ($q) => $q->where('codigo', '!=', 'ANULADO'))
            ->orderBy('id')->get();
    }

    public function test_el_jefe_devuelve_el_comentario_es_obligatorio_y_la_unidad_solo_toca_cinco_tipos(): void
    {
        $this->remitirLegalizados($this->proceso, $this->unidad, [24], 'AIL-5-M');
        $this->assertSame('LEGALIZADO', $this->estadoProceso());

        try {
            $this->procesos->aplicarTransicion($this->proceso->fresh(), $this->transicion('devolver_legalizacion'), $this->jefe);
            $this->fail('Debio exigir el motivo de la devolucion');
        } catch (DomainException $e) {
            $this->assertStringContainsString('comentario', mb_strtolower($e->getMessage()));
        }

        $this->procesos->aplicarTransicion(
            $this->proceso->fresh(), $this->transicion('devolver_legalizacion'), $this->jefe, 'Falta la ficha tecnica del item 3'
        );

        // Para revision: lo legalizado, el memorando y la solicitud no se tocan
        $this->assertSame('PARA_REVISION', $this->estadoProceso());
        $this->assertSame('LEGALIZADO', $this->estado($this->informe));
        $this->assertSame('LEGALIZADO', $this->estado($this->especificaciones));
        $this->assertSame('AIL-5-M', $this->proceso->fresh()->solicitudRemitida()->numero_memorando);
        $this->assertDatabaseHas('historial_estados_proceso', ['proceso_id' => $this->proceso->id, 'comentario' => 'Falta la ficha tecnica del item 3']);
        $this->assertSame(['remitir_correccion'], $this->codigosDisponibles($this->unidad));
        $this->assertSame([], $this->codigosDisponibles($this->jefe));

        $servicio = app(ServicioDocumento::class);
        $proceso = $this->proceso->fresh();
        // la unidad solo ve los tipos editables y Otros documentos; el analista y el Jefe no cargan en este estado
        $this->assertEqualsCanonicalizing(
            ['Distributivo', 'Fichas tecnicas', 'Certificado de no catalogado', 'Validacion de firmas electronicas', 'Autorizacion del requerimiento', 'Otros documentos'],
            $servicio->tiposDisponibles($proceso, $this->unidad)->values()->all()
        );
        $this->assertTrue($servicio->tiposDisponibles($proceso, $this->analista)->isEmpty());
        $this->assertTrue($servicio->tiposDisponibles($proceso, $this->jefe)->isEmpty());

        $fichas = $this->documentosDe('FICHAS_TECNICAS')->first();
        foreach ([$this->informe, $this->especificaciones, $this->documentosDe('MEMORANDO_SOLICITUD_PUBLICACION')->first()] as $bloqueado) {
            $this->assertFalse($servicio->puedeAgregarVersion($bloqueado, $this->unidad), $bloqueado->titulo);
            $this->assertFalse($servicio->puedeAnular($bloqueado, $this->unidad), $bloqueado->titulo);
        }
        $this->assertTrue($servicio->puedeAgregarVersion($fichas, $this->unidad));
        foreach (['DISTRIBUTIVO', 'VALIDACION_FIRMAS_ELECTRONICAS', 'CERTIFICADO_NO_CATALOGADO', 'AUTORIZACION_REQUERIMIENTO'] as $codigo) {
            $editable = $this->documentosDe($codigo)->first();
            $this->assertTrue($servicio->puedeAgregarVersion($editable, $this->unidad), "{$codigo}: nueva version");
            $this->assertTrue($servicio->puedeAnular($editable, $this->unidad), "{$codigo}: eliminar");
        }
        $this->assertFalse($servicio->puedeAgregarVersion($fichas, $this->analista));

        // nueva version (conserva el estado), documento nuevo (queda aprobado) y eliminar
        [$ruta, $nombre] = $this->pdfTemporal('fichas_v2.pdf');
        $servicio->agregarVersion($fichas, $ruta, $nombre, 'Se agrega el item 3', $this->unidad);
        $this->assertSame(2, $fichas->fresh()->versionActual->numero_version);
        $this->assertSame('APROBADO', $this->estado($fichas));

        [$ruta, $nombre] = $this->pdfTemporal('fichas_item3.pdf');
        $nueva = $servicio->crearDocumento($proceso, \App\Models\TipoDocumento::where('codigo', 'FICHAS_TECNICAS')->first(), 'Ficha item 3', $ruta, $nombre, null, $this->unidad);
        $this->assertSame('APROBADO', $this->estado($nueva));

        try {
            $servicio->anularDocumento($nueva, $this->unidad, '  ');
            $this->fail('Debio exigir el motivo');
        } catch (DomainException $e) {
            $this->assertStringContainsString('motivo', $e->getMessage());
        }
        $servicio->anularDocumento($nueva, $this->unidad, 'Duplicada');
        $this->assertSame('ANULADO', $this->estado($nueva));

        // el documento eliminado ya no se lista en Documentos y queda en el Historial de estados del proceso
        $this->actingAs($this->unidad);
        Livewire::test(DocumentosRelationManager::class, ['ownerRecord' => $this->proceso->fresh(), 'pageClass' => ViewProceso::class])
            ->assertCanSeeTableRecords([$fichas])
            ->assertCanNotSeeTableRecords([$nueva]);
        $registro = DB::table('historial_estados_proceso as h')
            ->join('transiciones_proceso as t', 't.id', '=', 'h.transicion_id')
            ->where('h.proceso_id', $this->proceso->id)->where('t.codigo', 'eliminar_documento')->first();
        $this->assertNotNull($registro);
        $this->assertStringContainsString('Documento eliminado: Ficha item 3', $registro->comentario);
        $this->assertStringContainsString('Motivo: Duplicada', $registro->comentario);
        $this->assertSame($this->unidad->id, $registro->user_id);
        $this->assertSame('PARA_REVISION', $this->proceso->fresh()->estadoProceso->codigo, 'eliminar no cambia el estado');
        $this->assertSame(2, $this->documentosDe('FICHAS_TECNICAS')->count(), 'la anulada queda como respaldo y no cuenta');

        // si elimina todas las fichas, la remision informa lo que falta
        foreach ($this->documentosDe('FICHAS_TECNICAS') as $f) {
            $servicio->anularDocumento($f, $this->unidad, 'Se reemplaza');
        }
        try {
            $this->procesos->aplicarTransicion($this->proceso->fresh(), $this->transicion('remitir_correccion'), $this->unidad);
            $this->fail('Debio exigir las fichas tecnicas');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Fichas tecnicas', $e->getMessage());
        }
        [$ruta, $nombre] = $this->pdfTemporal('fichas_nuevas.pdf');
        $servicio->crearDocumento($this->proceso->fresh(), \App\Models\TipoDocumento::where('codigo', 'FICHAS_TECNICAS')->first(), null, $ruta, $nombre, null, $this->unidad);

        // vuelve a remitir y queda Legalizado; el Jefe asigna y pasa a Para publicar
        $this->procesos->aplicarTransicion($this->proceso->fresh(), $this->transicion('remitir_correccion'), $this->unidad);
        $this->assertSame('LEGALIZADO', $this->estadoProceso());
        $this->assertSame(1, DB::table('solicitudes_publicacion')->count(), 'no se pide un memorando nuevo');
        $this->procesos->asignarAnalista($this->proceso->fresh(), $this->analista, $this->jefe);
        $this->assertSame('PARA_PUBLICAR', $this->estadoProceso());
    }

    public function test_el_analista_devuelve_desde_para_publicar_y_el_numero_del_proceso_se_conserva(): void
    {
        $this->remitirLegalizados($this->proceso, $this->unidad);
        $this->procesos->asignarAnalista($this->proceso->fresh(), $this->analista, $this->jefe);
        $this->assertSame('PARA_PUBLICAR', $this->estadoProceso());
        $codigo = $this->proceso->fresh()->codigo;
        $this->assertNotNull($codigo);

        // el comentario tambien es obligatorio para el analista
        try {
            $this->procesos->aplicarTransicion($this->proceso->fresh(), $this->transicion('devolver_publicacion'), $this->analista);
            $this->fail('Debio exigir el motivo');
        } catch (DomainException $e) {
            $this->assertStringContainsString('comentario', mb_strtolower($e->getMessage()));
        }
        $this->procesos->aplicarTransicion($this->proceso->fresh(), $this->transicion('devolver_publicacion'), $this->analista, 'Autorizacion sin firma');

        $this->assertSame('PARA_REVISION', $this->estadoProceso());
        $this->assertSame('LEGALIZADO', $this->estado($this->informe));
        $this->assertSame($codigo, $this->proceso->fresh()->codigo);

        $this->procesos->aplicarTransicion($this->proceso->fresh(), $this->transicion('remitir_correccion'), $this->unidad);
        $this->assertSame('LEGALIZADO', $this->estadoProceso());
        $this->procesos->asignarAnalista($this->proceso->fresh(), $this->analista, $this->jefe);
        $this->assertSame('PARA_PUBLICAR', $this->estadoProceso());
        $this->assertSame($codigo, $this->proceso->fresh()->codigo, 'no se genera un codigo nuevo');
    }

    public function test_descarga_del_pdf_legalizado_solo_con_permiso_y_si_esta_legalizado(): void
    {
        $url = route('documentos.descargar_legalizado', $this->informe);

        $this->get($url)->assertRedirect('/admin/login');

        // aun no legalizado
        $this->actingAs($this->unidad)->get($url)->assertNotFound();

        $this->remitirLegalizados($this->proceso, $this->unidad);

        $this->actingAs(User::factory()->conRoles('UNIDAD_REQUIRENTE')->create())->get($url)->assertForbidden();

        $respuesta = $this->actingAs($this->unidad)->get($url);
        $respuesta->assertOk();
        $this->assertStringContainsString('informe_necesidad_firmado.pdf', $respuesta->headers->get('content-disposition'));
        $this->assertDatabaseHas('auditoria', ['evento' => 'documento.legalizado_descargado', 'entidad_id' => $this->informe->id]);
        $this->actingAs($this->jefe)->get($url)->assertOk();
    }

    public function test_la_pantalla_muestra_el_boton_a_la_unidad_y_la_solicitud_a_todos(): void
    {
        $this->actingAs($this->unidad);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionVisible('transicion_remitir_legalizados')
            ->assertSee('Remitir documentos legalizados');

        $this->actingAs($this->analista);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionDoesNotExist('transicion_remitir_legalizados');

        $this->remitirLegalizados($this->proceso, $this->unidad, [24, 72], 'AIL-GER-2026-777-M');

        $this->actingAs($this->unidad);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionDoesNotExist('transicion_remitir_legalizados')
            ->assertSee('AIL-GER-2026-777-M')
            ->assertSee('24 h (1 día)');

        $this->actingAs($this->jefe);
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionVisible('transicion_devolver_legalizacion');

        $documento = $this->informe->fresh();
        Livewire::test(DocumentosRelationManager::class, ['ownerRecord' => $this->proceso->fresh(), 'pageClass' => ViewProceso::class])
            ->assertTableActionVisible('descargar_legalizado', $documento);
    }
}
