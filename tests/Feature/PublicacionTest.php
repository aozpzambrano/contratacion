<?php

namespace Tests\Feature;

use App\Filament\Resources\Procesos\Pages\ViewProceso;
use App\Filament\Resources\Procesos\RelationManagers\DocumentosRelationManager;
use App\Models\Documento;
use App\Models\DocumentoFijo;
use App\Models\Proceso;
use App\Models\TipoDocumento;
use App\Models\TransicionProceso;
use App\Models\User;
use App\Services\PlantillaWord;
use App\Services\ServicioDocumento;
use App\Services\ServicioDocumentoFijo;
use App\Services\ServicioExpedientePublicacion;
use App\Services\ServicioProceso;
use App\Services\ServicioPublicacion;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreaProcesos;
use Tests\TestCase;
use ZipArchive;

/** Documentos fijos, plantilla Word, expediente de publicacion (zip) y registro de la publicacion. */
class PublicacionTest extends TestCase
{
    use CreaProcesos;
    use RefreshDatabase;

    private ServicioProceso $procesos;

    private User $unidad;

    private User $analista;

    private User $jefe;

    private User $admin;

    private Proceso $proceso;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ServicioDocumento::DISCO);
        $this->procesos = app(ServicioProceso::class);

        $this->unidad = User::factory()->conRoles('UNIDAD_REQUIRENTE')->create();
        $this->analista = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $this->jefe = User::factory()->conRoles('COMPRAS_JEFE')->create();
        $this->admin = User::factory()->conRoles('ADMIN')->create();

        $p = $this->proceso($this->unidad, 'Adquisición de medicamentos & <insumos>');
        $this->asignar($p, $this->unidad, 'UNIDAD_REQUIRENTE', $this->unidad);
        $this->subir($p, 'INFORME_NECESIDAD');
        $this->subir($p, 'ESPECIFICACIONES_TECNICAS');
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

    /** La unidad remite y el Jefe asigna al analista: el proceso queda "Para publicar" con su codigo. */
    private function dejarParaPublicar(array $horas = [24, 48]): void
    {
        $this->remitirLegalizados($this->proceso, $this->unidad, $horas);
        $this->procesos->asignarAnalista($this->proceso->fresh(), $this->analista, $this->jefe);
        $this->proceso = $this->proceso->fresh();
        $this->assertSame('PARA_PUBLICAR', $this->proceso->estadoProceso->codigo);
    }

    /** .docx minimo: un marcador partido en dos fragmentos y otro entero. */
    private function docx(bool $conMarcadores = true): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'docx_');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $cuerpo = $conMarcadores
            ? '<w:p><w:r><w:t>Objeto: {{objeto_</w:t></w:r><w:r><w:t>contratacion}}</w:t></w:r></w:p>'
              .'<w:p><w:r><w:t xml:space="preserve">Proceso {{numero_proceso}} fin</w:t></w:r></w:p>'
            : '<w:p><w:r><w:t>Sin marcadores</w:t></w:r></w:p>';
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$cuerpo.'</w:body></w:document>');
        $zip->close();

        return $ruta;
    }

    private function cargarFijo(string $codigo, string $contenido, string $nombre, ?User $usuario = null): DocumentoFijo
    {
        $fijo = DocumentoFijo::where('codigo', $codigo)->firstOrFail();
        $temporal = 'temporal/'.Str::uuid().'.tmp';
        Storage::disk(ServicioDocumento::DISCO)->put($temporal, $contenido);
        app(ServicioDocumentoFijo::class)->reemplazar($fijo, $temporal, $nombre, $usuario ?? $this->admin);

        return $fijo->fresh();
    }

    /** Carga los cuatro documentos fijos con contenido de prueba. */
    private function cargarTodosLosFijos(): void
    {
        $this->cargarFijo('REGLAMENTO_INTERNO', "%PDF-1.4\nreglamento", 'Reglamento_Interno.pdf');
        $this->cargarFijo('PROYECTO_ORDEN_COMPRA', file_get_contents($this->docx(false)), 'Proyecto_Orden_Compra.docx');
        $this->cargarFijo('ANEXO_VINCULACIONES', "\xD0\xCF\x11\xE0 anexo", 'ANEXO_VINCULACIONES.doc');
        $this->cargarFijo('ANEXO_I_PERFIL_INTEGRIDAD', file_get_contents($this->docx()), 'ANEXO_I.docx');
    }

    // ------------------------------------------------------------ plantilla Word

    public function test_la_plantilla_rellena_los_marcadores_aunque_word_los_parta_en_fragmentos(): void
    {
        $plantilla = new PlantillaWord;
        $origen = $this->docx();

        $this->assertEqualsCanonicalizing(['objeto_contratacion', 'numero_proceso'], $plantilla->marcadoresPresentes($origen));
        $this->assertSame([], $plantilla->marcadoresPresentes($this->docx(false)));

        $salida = $plantilla->rellenar($origen, ['objeto_contratacion' => 'Medicinas & <insumos>', 'numero_proceso' => 'PE-AILEP-2026-007']);

        $zip = new ZipArchive;
        $zip->open($salida);
        $xml = $zip->getFromName('word/document.xml');
        $this->assertStringContainsString('Objeto: Medicinas &amp; &lt;insumos&gt;', $xml);
        $this->assertStringContainsString('Proceso PE-AILEP-2026-007 fin', $xml);
        $this->assertStringNotContainsString('{{', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'el XML resultante debe seguir siendo valido');
        $zip->close();
    }

    // ------------------------------------------------------------ documentos fijos (administracion)

    public function test_el_administrador_reemplaza_un_fijo_y_la_version_anterior_queda_de_respaldo(): void
    {
        $fijo = DocumentoFijo::where('codigo', 'REGLAMENTO_INTERNO')->firstOrFail();
        $this->assertNull($fijo->archivo_id);

        $v1 = $this->cargarFijo('REGLAMENTO_INTERNO', "%PDF-1.4\nuno", 'Reglamento_v1.pdf');
        $v2 = $this->cargarFijo('REGLAMENTO_INTERNO', "%PDF-1.4\ndos", 'Reglamento_v2.pdf');

        $this->assertNotSame($v1->archivo_id, $v2->archivo_id);
        $this->assertSame(['Reglamento_v2.pdf', 'Reglamento_v1.pdf'], $v2->versiones()->with('archivo')->get()->map(fn ($v) => $v->archivo->nombre_original)->all());
        $this->assertSame([2, 1], $v2->versiones->pluck('numero_version')->map(fn ($n) => (int) $n)->all());
        // el archivo anterior sigue en el disco
        $anterior = $v2->versiones->last()->archivo;
        $this->assertTrue(Storage::disk(ServicioDocumento::DISCO)->exists($anterior->ruta));
        $this->assertDatabaseHas('auditoria', ['evento' => 'documento_fijo.reemplazado', 'entidad_id' => $fijo->id]);
    }

    public function test_el_reemplazo_valida_formato_contenido_marcadores_y_permiso(): void
    {
        $casos = [
            'extension no permitida' => ['REGLAMENTO_INTERNO', "%PDF-1.4\nx", 'reglamento.docx', 'Formatos aceptados: PDF'],
            'pdf falso' => ['REGLAMENTO_INTERNO', 'no es pdf', 'reglamento.pdf', 'no corresponde al formato PDF'],
            'archivo vacio' => ['REGLAMENTO_INTERNO', '', 'reglamento.pdf', 'vacio'],
            'plantilla sin marcadores' => ['ANEXO_I_PERFIL_INTEGRIDAD', file_get_contents($this->docx(false)), 'anexo.docx', 'debe conservar los marcadores'],
            'plantilla en doc' => ['ANEXO_I_PERFIL_INTEGRIDAD', "\xD0\xCF\x11\xE0 x", 'anexo.doc', 'Formatos aceptados: DOCX'],
        ];
        foreach ($casos as $nombre => [$codigo, $contenido, $archivo, $mensaje]) {
            try {
                $this->cargarFijo($codigo, $contenido, $archivo);
                $this->fail("Debio rechazar: {$nombre}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage(), $nombre);
            }
            $this->assertNull(DocumentoFijo::where('codigo', $codigo)->value('archivo_id'), $nombre);
        }
        $this->assertSame([], Storage::disk(ServicioDocumento::DISCO)->allFiles('temporal'), 'no quedan temporales');

        try {
            $this->cargarFijo('REGLAMENTO_INTERNO', "%PDF-1.4\nx", 'r.pdf', $this->analista);
            $this->fail('Solo el administrador reemplaza documentos fijos');
        } catch (DomainException $e) {
            $this->assertStringContainsString('No tiene permiso', $e->getMessage());
        }
    }

    public function test_solo_el_administrador_ve_la_pantalla_de_documentos_fijos(): void
    {
        $this->actingAs($this->admin);
        $this->get('/admin/documentos-fijos')->assertOk()->assertSee('Anexo I Formulario Perfil Integridad Proveedor');

        foreach ([$this->analista, $this->jefe, $this->unidad] as $otro) {
            $this->actingAs($otro);
            $this->get('/admin/documentos-fijos')->assertForbidden();
        }
    }

    // ------------------------------------------------------------ visibilidad y expediente

    public function test_compras_ve_los_fijos_solo_cuando_el_proceso_esta_listo_para_publicar(): void
    {
        $this->cargarTodosLosFijos();
        $fijos = app(ServicioDocumentoFijo::class);

        // todavia en la unidad (Aprobado): nadie de Compras los ve
        $this->assertFalse($fijos->puedeVer($this->proceso, $this->analista));

        $this->dejarParaPublicar();
        $this->assertTrue($fijos->puedeVer($this->proceso, $this->analista));
        $this->assertTrue($fijos->puedeVer($this->proceso, $this->jefe));
        $this->assertFalse($fijos->puedeVer($this->proceso, $this->unidad), 'la unidad no ve los documentos fijos');
        $this->assertCount(4, $fijos->disponibles($this->proceso));

        // devuelto a la unidad: Compras deja de verlos
        $devolver = $this->transicion('devolver_publicacion');
        $this->procesos->aplicarTransicion($this->proceso->fresh(), $devolver, $this->analista, 'Falta una ficha');
        $this->assertFalse($fijos->puedeVer($this->proceso->fresh(), $this->analista));
    }

    public function test_el_expediente_trae_todo_marcado_por_defecto(): void
    {
        $this->cargarTodosLosFijos();
        $this->dejarParaPublicar();
        $servicio = app(ServicioExpedientePublicacion::class);

        $opciones = $servicio->opciones($this->proceso);
        $this->assertTrue($opciones->isNotEmpty());
        $this->assertSame([], $opciones->where('marcado', false)->keys()->all(), 'todo viene marcado');
        $this->assertCount($opciones->count(), $servicio->marcadasPorDefecto($this->proceso));
        $this->assertTrue($opciones->contains(fn ($o) => str_starts_with($o['etiqueta'], 'Validacion de firmas')));
        $this->assertTrue($opciones->contains(fn ($o) => str_starts_with($o['etiqueta'], 'Autorizacion del requerimiento')));
        $this->assertTrue($opciones->contains(fn ($o) => str_starts_with($o['etiqueta'], 'Reglamento Interno')));
    }

    public function test_la_unidad_genera_el_expediente_cuando_el_proceso_esta_legalizado_sin_los_fijos(): void
    {
        $this->cargarTodosLosFijos();
        $this->remitirLegalizados($this->proceso, $this->unidad);
        $this->proceso = $this->proceso->fresh();
        $this->assertSame('LEGALIZADO', $this->proceso->estadoProceso->codigo);
        $servicio = app(ServicioExpedientePublicacion::class);

        $this->assertTrue($servicio->puedeGenerar($this->proceso, $this->unidad));
        $claves = $servicio->marcadasPorDefecto($this->proceso, $this->unidad);
        $this->assertNotEmpty($claves);
        $this->assertSame([], preg_grep('/^fijo_/', $claves), 'los documentos fijos son solo de Compras');
        $this->assertCount($servicio->opciones($this->proceso, $this->unidad)->count(), $claves);

        [$ruta] = $servicio->generar($this->proceso, $this->unidad, $claves);
        $zip = new ZipArchive;
        $zip->open($ruta);
        $this->assertGreaterThan(0, $zip->numFiles);
        $zip->close();
        @unlink($ruta);

        $this->actingAs($this->unidad);
        Livewire::test(DocumentosRelationManager::class, ['ownerRecord' => $this->proceso, 'pageClass' => ViewProceso::class])
            ->assertSee('Generar Expediente');

        // en otros estados (por ejemplo, Para publicar) la unidad ya no lo genera
        $this->procesos->asignarAnalista($this->proceso->fresh(), $this->analista, $this->jefe);
        $this->assertFalse($servicio->puedeGenerar($this->proceso->fresh(), $this->unidad));
    }

    public function test_el_zip_incluye_lo_elegido_y_rellena_el_anexo_i(): void
    {
        $this->cargarTodosLosFijos();
        $this->dejarParaPublicar();
        $servicio = app(ServicioExpedientePublicacion::class);
        $codigo = $this->proceso->codigo;

        [$ruta, $nombre] = $servicio->generar($this->proceso, $this->analista, $servicio->marcadasPorDefecto($this->proceso));
        $this->assertSame("Expediente_Publicacion_{$codigo}.zip", $nombre);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta));
        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }
        $this->assertCount(count($servicio->marcadasPorDefecto($this->proceso)), $nombres);
        $this->assertCount(count($nombres), array_unique($nombres), 'nombres unicos dentro del zip');
        $this->assertNotEmpty(preg_grep('/reglamento_interno\.pdf$/i', $nombres));
        $this->assertEmpty(preg_grep('/validacion_de_firmas/i', $nombres), 'Validación de Firmas no va por defecto');
        $this->assertEmpty(preg_grep('/autorizacion_del_requerimiento/i', $nombres));

        // el Anexo I sale como Word con el objeto y el numero del proceso
        $anexo = array_values(preg_grep('/anexo_i_formulario/i', $nombres));
        $this->assertCount(1, $anexo);
        $this->assertStringEndsWith("{$codigo}.docx", $anexo[0]);
        $interno = tempnam(sys_get_temp_dir(), 'anexo_');
        file_put_contents($interno, $zip->getFromName($anexo[0]));
        $zip->close();
        $docx = new ZipArchive;
        $docx->open($interno);
        $xml = $docx->getFromName('word/document.xml');
        $docx->close();
        $this->assertStringContainsString("Proceso {$codigo} fin", $xml);
        $this->assertStringContainsString('Adquisición de medicamentos &amp; &lt;insumos&gt;', $xml);
        @unlink($ruta);
        @unlink($interno);

        $this->assertDatabaseHas('auditoria', ['evento' => 'expediente.publicacion_generado', 'entidad_id' => $this->proceso->id]);
    }

    public function test_el_distributivo_admite_varios_archivos_pdf_word_y_excel(): void
    {
        $distributivos = [
            $this->pdfTemporal('distributivo.pdf', "%PDF-1.4\nd"),
            $this->pdfTemporal('distributivo.docx', 'word'),
            $this->pdfTemporal('distributivo.xlsx', 'excel'),
        ];
        $this->remitirLegalizados($this->proceso, $this->unidad, [24, 48], 'AIL-GER-2026-001-M', $distributivos);

        $docs = Documento::where('proceso_id', $this->proceso->id)
            ->whereHas('tipoDocumento', fn ($q) => $q->where('codigo', 'DISTRIBUTIVO'))->with('versionActual.archivo')->get();
        $this->assertCount(3, $docs);
        $this->assertEqualsCanonicalizing(['pdf', 'docx', 'xlsx'], $docs->map(fn ($d) => $d->versionActual->archivo->extension)->all());
    }

    public function test_el_distributivo_rechaza_otros_formatos_y_no_deja_nada(): void
    {
        $antes = Documento::where('proceso_id', $this->proceso->id)->count();
        try {
            $this->remitirLegalizados($this->proceso, $this->unidad, [24], 'AIL-GER-2026-001-M', [$this->pdfTemporal('distributivo.zip', 'x')]);
            $this->fail('Debio rechazar el formato');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Tipo de archivo no permitido', $e->getMessage());
        }
        $this->assertSame($antes, Documento::where('proceso_id', $this->proceso->id)->count());
        $this->assertSame('PARA_LEGALIZAR', $this->proceso->fresh()->estadoProceso->codigo);
    }

    public function test_el_expediente_agrupa_en_carpetas_por_tipo_de_documento(): void
    {
        $this->cargarTodosLosFijos();
        $this->remitirLegalizados($this->proceso, $this->unidad, [24, 48], 'AIL-GER-2026-001-M', [
            $this->pdfTemporal('dist_1.xlsx', 'excel'), $this->pdfTemporal('dist_2.docx', 'word'),
        ]);
        $this->procesos->asignarAnalista($this->proceso->fresh(), $this->analista, $this->jefe);
        $this->proceso = $this->proceso->fresh();
        $servicio = app(ServicioExpedientePublicacion::class);

        // todo marcado, incluidos los que vienen sin marcar
        $todas = $servicio->opciones($this->proceso)->keys()->all();
        [$ruta] = $servicio->generar($this->proceso, $this->analista, $todas);
        $zip = new ZipArchive;
        $zip->open($ruta);
        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($ruta);

        $enCarpeta = fn (string $carpeta) => count(preg_grep('#^'.preg_quote($carpeta, '#').'/[^/]+$#u', $nombres));
        $this->assertSame(1, $enCarpeta('01. Informe de Necesidad'));
        $this->assertSame(1, $enCarpeta('02. Especificaciones Técnicas'));
        $this->assertSame(2, $enCarpeta('03. Distributivo'));
        $this->assertGreaterThanOrEqual(1, $enCarpeta('04. Fichas'));
        $this->assertGreaterThanOrEqual(1, $enCarpeta('05. Certificado de no catalogado'));
        $this->assertGreaterThanOrEqual(1, $enCarpeta('06. Validación de Firmas'));
        $this->assertGreaterThanOrEqual(1, $enCarpeta('07. Autorización del Requerimiento'));

        // memorando y documentos fijos: sueltos, sin carpeta ni numeracion
        $sueltos = array_values(array_filter($nombres, fn ($n) => ! str_contains($n, '/')));
        $this->assertNotEmpty(preg_grep('/^memorando_de_solicitud_de_publicacion/i', $sueltos));
        $this->assertNotEmpty(preg_grep('/reglamento_interno\.pdf$/i', $sueltos));
        $this->assertCount(count($nombres), array_unique($nombres));
        foreach ($sueltos as $n) {
            $this->assertDoesNotMatchRegularExpression('/^\d/', $n);
        }
        $this->assertSame(count($nombres), count($sueltos) + count(array_filter($nombres, fn ($n) => str_contains($n, '/'))));
    }

    public function test_el_distributivo_es_obligatorio_para_remitir(): void
    {
        $tipos = app(\App\Services\ServicioLegalizacion::class)->tiposAdicionalesPendientes($this->proceso);
        $this->assertSame(
            ['DISTRIBUTIVO', 'FICHAS_TECNICAS', 'CERTIFICADO_NO_CATALOGADO', 'VALIDACION_FIRMAS_ELECTRONICAS', 'AUTORIZACION_REQUERIMIENTO'],
            $tipos->pluck('codigo')->all(),
            'el modal pide primero el Distributivo y luego las fichas y certificados'
        );

        // todo menos el Distributivo: la base informa que falta
        $legalizados = [];
        foreach (app(ServicioDocumento::class)->documentosPorLegalizar($this->proceso) as $d) {
            $legalizados[$d->id] = $this->pdfTemporal('l'.$d->id.'.pdf');
        }
        $adicionales = [];
        foreach ($tipos->where('codigo', '!=', 'DISTRIBUTIVO') as $t) {
            $adicionales[$t->id] = [$this->pdfTemporal($t->codigo.'.pdf')];
        }
        try {
            app(\App\Services\ServicioLegalizacion::class)->remitir(
                $this->proceso->fresh(), $this->unidad, $legalizados, $this->pdfTemporal('m.pdf'), 'AIL-9', [24], $adicionales
            );
            $this->fail('Debio exigir el Distributivo');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Distributivo', $e->getMessage());
        }
        $this->assertSame('PARA_LEGALIZAR', $this->proceso->fresh()->estadoProceso->codigo);

        // con el Distributivo (un solo archivo Word) se remite
        $this->remitirLegalizados($this->proceso, $this->unidad, [24], 'AIL-9', [$this->pdfTemporal('distributivo.docx', 'word')]);
        $this->assertSame('LEGALIZADO', $this->proceso->fresh()->estadoProceso->codigo);
    }

    public function test_los_documentos_se_muestran_en_el_orden_del_expediente(): void
    {
        $this->remitirLegalizados($this->proceso, $this->unidad, [24, 48], 'AIL-GER-2026-001-M', [$this->pdfTemporal('dist.xlsx', 'excel')]);
        $this->procesos->asignarAnalista($this->proceso->fresh(), $this->analista, $this->jefe);
        $this->proceso = $this->proceso->fresh();
        $servicio = app(ServicioExpedientePublicacion::class);

        $orden = Documento::query()->where('proceso_id', $this->proceso->id)
            ->orderByRaw(ServicioExpedientePublicacion::sqlPosicionTipo())->orderBy('id')
            ->with('tipoDocumento')->get()->pluck('tipoDocumento.codigo')->unique()->values()->all();
        $this->assertSame([
            'INFORME_NECESIDAD', 'ESPECIFICACIONES_TECNICAS', 'DISTRIBUTIVO', 'FICHAS_TECNICAS', 'CERTIFICADO_NO_CATALOGADO',
            'VALIDACION_FIRMAS_ELECTRONICAS', 'AUTORIZACION_REQUERIMIENTO', 'MEMORANDO_SOLICITUD_PUBLICACION',
        ], $orden);

        // la tabla de la seccion Documentos sigue ese mismo orden
        $esperados = Documento::query()->where('proceso_id', $this->proceso->id)
            ->orderByRaw(ServicioExpedientePublicacion::sqlPosicionTipo())->orderBy('id')->get();
        $this->actingAs($this->analista);
        Livewire::test(DocumentosRelationManager::class, ['ownerRecord' => $this->proceso, 'pageClass' => ViewProceso::class])
            ->assertCanSeeTableRecords($esperados, inOrder: true);

        // la lista para descargar el expediente sigue el mismo orden (los documentos fijos van al final)
        $etiquetas = $servicio->opciones($this->proceso)->pluck('etiqueta')->values()->all();
        $posicion = fn (string $texto) => collect($etiquetas)->search(fn ($e) => str_starts_with($e, $texto));
        $this->assertLessThan($posicion('Especificaciones tecnicas'), $posicion('Informe de necesidad'));
        $this->assertLessThan($posicion('Distributivo'), $posicion('Especificaciones tecnicas'));
        $this->assertLessThan($posicion('Fichas tecnicas'), $posicion('Distributivo'));
        $this->assertLessThan($posicion('Certificado de no catalogado'), $posicion('Fichas tecnicas'));
        $this->assertLessThan($posicion('Validacion de firmas'), $posicion('Certificado de no catalogado'));
        $this->assertLessThan($posicion('Autorizacion del requerimiento'), $posicion('Validacion de firmas'));
        $this->assertLessThan($posicion('Memorando de solicitud'), $posicion('Autorizacion del requerimiento'));
    }

    public function test_varios_archivos_del_mismo_tipo_quedan_todos_dentro_de_su_carpeta(): void
    {
        $this->cargarTodosLosFijos();
        $this->remitirLegalizados($this->proceso, $this->unidad, [24, 48], 'AIL-GER-2026-001-M', [
            $this->pdfTemporal('a.xlsx', 'excel'), $this->pdfTemporal('b.xlsx', 'excel'), $this->pdfTemporal('c.docx', 'word'),
        ]);
        $this->procesos->asignarAnalista($this->proceso->fresh(), $this->analista, $this->jefe);
        $this->proceso = $this->proceso->fresh();
        $servicio = app(ServicioExpedientePublicacion::class);

        // 2 fichas tecnicas (el helper carga dos) y 3 distributivos
        $this->assertSame(2, Documento::where('proceso_id', $this->proceso->id)->whereHas('tipoDocumento', fn ($q) => $q->where('codigo', 'FICHAS_TECNICAS'))->count());

        [$ruta] = $servicio->generar($this->proceso, $this->analista, $servicio->opciones($this->proceso)->keys()->all());
        $zip = new ZipArchive;
        $zip->open($ruta);
        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($ruta);

        $this->assertCount(2, preg_grep('#^04\. Fichas/[^/]+$#u', $nombres), 'las dos fichas en su carpeta');
        $this->assertCount(3, preg_grep('#^03\. Distributivo/[^/]+$#u', $nombres), 'los tres distributivos en su carpeta');
        $this->assertCount(count($nombres), array_unique($nombres));
        // nada de esos tipos queda suelto en la raiz
        $this->assertEmpty(preg_grep('#^(fichas|distributivo)[^/]*$#i', $nombres));
    }

    public function test_se_puede_incluir_lo_que_viene_sin_marcar_y_no_se_genera_vacio_ni_sin_permiso(): void
    {
        $this->cargarTodosLosFijos();
        $this->dejarParaPublicar();
        $servicio = app(ServicioExpedientePublicacion::class);

        $firmas = $servicio->opciones($this->proceso)->first(fn ($o) => str_starts_with($o['etiqueta'], 'Validacion de firmas'));
        [$ruta] = $servicio->generar($this->proceso, $this->analista, [$firmas['clave']]);
        $zip = new ZipArchive;
        $zip->open($ruta);
        $this->assertSame(1, $zip->numFiles);
        $zip->close();
        @unlink($ruta);

        try {
            $servicio->generar($this->proceso, $this->analista, []);
            $this->fail('No debe generar un expediente vacio');
        } catch (DomainException $e) {
            $this->assertStringContainsString('al menos un documento', $e->getMessage());
        }
        try {
            $servicio->generar($this->proceso, $this->unidad, [$firmas['clave']]);
            $this->fail('La unidad no genera el expediente');
        } catch (DomainException $e) {
            $this->assertStringContainsString('No puede generar', $e->getMessage());
        }
    }

    // ------------------------------------------------------------ publicar

    private function publicar(array $reemplazos = []): void
    {
        $d = array_merge([
            'necesidad' => 'NC-2026-0145',
            'enlace' => 'https://www.compraspublicas.gob.ec/proceso/123',
            'desde' => '2026-12-01 08:30',
            'hasta' => '2026-12-05 17:00',
        ], $reemplazos);

        app(ServicioPublicacion::class)->registrar($this->proceso->fresh(), $this->analista, $d['necesidad'], $d['enlace'], $d['desde'], $d['hasta']);
    }

    public function test_publicar_guarda_los_datos_y_pasa_el_proceso_a_publicado(): void
    {
        $this->dejarParaPublicar();
        $this->assertSame(2, app(ServicioPublicacion::class)->publicacionesSolicitadas($this->proceso));

        $this->publicar();

        $this->assertSame('PUBLICADO', $this->proceso->fresh()->estadoProceso->codigo);
        $p = $this->proceso->fresh()->publicaciones()->firstOrFail();
        $this->assertSame(1, (int) $p->secuencia);
        $this->assertSame('NC-2026-0145', $p->codigo_nc);
        $this->assertSame('https://www.compraspublicas.gob.ec/proceso/123', $p->enlace);
        $this->assertTrue($p->fecha_publicacion->equalTo(Carbon::parse('2026-12-01 08:30')), 'guarda fecha y hora');
        $this->assertTrue($p->fecha_limite->equalTo(Carbon::parse('2026-12-05 17:00')));
        $this->assertDatabaseHas('auditoria', ['evento' => 'proceso.publicacion_registrada', 'entidad_id' => $p->id]);
    }

    public function test_la_fecha_limite_debe_ser_posterior_a_la_de_publicacion_incluida_la_hora(): void
    {
        $this->dejarParaPublicar();

        try {
            $this->publicar(['desde' => '2026-12-05 10:00', 'hasta' => '2026-12-05 09:59']);
            $this->fail('La fecha limite no puede ser anterior');
        } catch (DomainException $e) {
            $this->assertStringContainsString('posterior a la Fecha de Publicación', $e->getMessage());
        }
        $this->assertSame('PARA_PUBLICAR', $this->proceso->fresh()->estadoProceso->codigo);

        // igual tampoco vale: debe ser posterior
        try {
            $this->publicar(['desde' => '2026-12-05 10:00', 'hasta' => '2026-12-05 10:00']);
            $this->fail('La fecha limite no puede ser igual');
        } catch (DomainException $e) {
            $this->assertStringContainsString('posterior a la Fecha de Publicación', $e->getMessage());
        }
        $this->assertSame('PARA_PUBLICAR', $this->proceso->fresh()->estadoProceso->codigo);

        $this->publicar(['desde' => '2026-12-05 10:00', 'hasta' => '2026-12-05 10:01']);
        $this->assertSame('PUBLICADO', $this->proceso->fresh()->estadoProceso->codigo);
    }

    public function test_datos_invalidos_no_dejan_nada_registrado(): void
    {
        $this->dejarParaPublicar();

        $casos = [
            'necesidad vacia' => [['necesidad' => '   '], 'Necesidad de Contratación'],
            'necesidad de mas de 60' => [['necesidad' => str_repeat('N', 61)], 'no puede superar 60'],
            'enlace vacio' => [['enlace' => ''], 'link de publicación válido'],
            'enlace sin protocolo' => [['enlace' => 'www.compraspublicas.gob.ec'], 'link de publicación válido'],
            'fecha sin hora' => [['desde' => '2026-12-01'], 'fecha y hora'],
            'fecha inexistente' => [['desde' => '2026-02-31 10:00'], 'Fecha y hora de Publicación'],
            'hora inexistente' => [['desde' => '2026-12-01 25:10'], 'Fecha y hora de Publicación'],
            'limite vacia' => [['hasta' => ''], 'Fecha y hora Límite'],
        ];
        foreach ($casos as $nombre => [$datos, $mensaje]) {
            try {
                $this->publicar($datos);
                $this->fail("Debio rechazar: {$nombre}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage(), $nombre);
            }
            $this->assertSame(0, DB::table('publicaciones')->count(), $nombre);
            $this->assertSame('PARA_PUBLICAR', $this->proceso->fresh()->estadoProceso->codigo, $nombre);
        }
    }

    public function test_solo_el_analista_asignado_publica(): void
    {
        $this->dejarParaPublicar();
        $servicio = app(ServicioPublicacion::class);

        $this->assertTrue($servicio->puedePublicar($this->proceso, $this->analista));
        $this->assertFalse($servicio->puedePublicar($this->proceso, $this->unidad));
        $otro = User::factory()->conRoles('COMPRAS_ANALISTA')->create();
        $this->assertFalse($servicio->puedePublicar($this->proceso, $otro), 'un analista no asignado no publica');

        $this->expectException(DomainException::class);
        $servicio->registrar($this->proceso, $this->unidad, 'x', 'https://a.ec', '2026-12-01 08:00', '2026-12-02 08:00');
    }

    public function test_no_se_pueden_registrar_mas_publicaciones_que_las_solicitadas_por_la_unidad(): void
    {
        $this->dejarParaPublicar([24, 48]);

        foreach ([1, 2] as $n) {
            DB::table('publicaciones')->insert([
                'proceso_id' => $this->proceso->id, 'ciclo' => $this->proceso->ciclo, 'secuencia' => $n,
                'codigo_nc' => "Necesidad {$n}", 'enlace' => 'https://a.ec', 'fecha_publicacion' => '2026-12-01 08:00', 'fecha_limite' => '2026-12-02 08:00',
            ]);
        }

        // La tercera la rechaza la base (la unidad solo pidio dos).
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('publicaciones solicitadas');
        DB::table('publicaciones')->insert([
            'proceso_id' => $this->proceso->id, 'ciclo' => $this->proceso->ciclo, 'secuencia' => 3,
            'codigo_nc' => 'Necesidad 3', 'enlace' => 'https://a.ec', 'fecha_publicacion' => '2026-12-01 08:00', 'fecha_limite' => '2026-12-02 08:00',
        ]);
    }

    // ------------------------------------------------------------ pantalla

    public function test_el_analista_publica_desde_la_pantalla_del_proceso(): void
    {
        $this->cargarTodosLosFijos();
        $this->dejarParaPublicar();
        $this->actingAs($this->analista);

        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertActionVisible('transicion_publicar')
            ->assertSee('Documentos fijos')
            // fecha limite igual o anterior: el formulario la rechaza
            ->callAction('transicion_publicar', [
                'necesidad' => 'Necesidad X', 'enlace' => 'https://sitio.ec/p/1',
                'fecha_publicacion' => '2026-12-10 09:00', 'fecha_limite' => '2026-12-10 09:00',
            ])
            ->assertHasActionErrors(['fecha_limite']);
        $this->assertSame('PARA_PUBLICAR', $this->proceso->fresh()->estadoProceso->codigo);

        // con el error el modal queda abierto: se reintenta en una pantalla nueva
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])->callAction('transicion_publicar', [
            'necesidad' => 'Necesidad X', 'enlace' => 'https://sitio.ec/p/1',
            'fecha_publicacion' => '2026-12-10 09:00', 'fecha_limite' => '2026-12-10 09:01',
        ])->assertHasNoActionErrors();

        $this->assertSame('PUBLICADO', $this->proceso->fresh()->estadoProceso->codigo);
        $this->assertSame('Necesidad X', $this->proceso->fresh()->publicaciones()->first()->codigo_nc);

        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertSee('Necesidad X')
            ->assertSee('10/12/2026 09:00');
    }

    public function test_el_boton_del_expediente_y_los_fijos_solo_los_ve_compras(): void
    {
        $this->cargarTodosLosFijos();
        $this->dejarParaPublicar();

        foreach ([$this->analista, $this->jefe] as $compras) {
            $this->actingAs($compras);
            Livewire::test(DocumentosRelationManager::class, ['ownerRecord' => $this->proceso->fresh(), 'pageClass' => ViewProceso::class])
                ->assertSee('Generar Expediente');
        }

        $this->actingAs($this->unidad);
        Livewire::test(DocumentosRelationManager::class, ['ownerRecord' => $this->proceso->fresh(), 'pageClass' => ViewProceso::class])
            ->assertDontSee('Generar Expediente');
        Livewire::test(ViewProceso::class, ['record' => $this->proceso->getRouteKey()])
            ->assertDontSee('Documentos fijos');
    }
}
