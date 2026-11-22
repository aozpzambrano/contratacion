<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogoSeguridadTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_migracion_carga_roles_fases_y_permisos(): void
    {
        $this->assertSame(10, DB::table('roles')->count());
        $this->assertSame(3, DB::table('fases')->count());
        $this->assertGreaterThan(40, DB::table('permisos')->count());
    }

    public function test_formato_de_codigo_de_permiso(): void
    {
        $malos = DB::table('permisos')->whereRaw("codigo !~ '^[a-z_]+\\.[a-z_]+$'")->count();
        $this->assertSame(0, $malos);
    }

    public function test_descarga_de_expediente_solo_para_analista_unidad_requirente_y_admin_contrato(): void
    {
        $roles = DB::table('rol_permiso as rp')
            ->join('roles as r', 'r.id', '=', 'rp.rol_id')
            ->join('permisos as p', 'p.id', '=', 'rp.permiso_id')
            ->where('p.codigo', 'expedientes.descargar')
            ->orderBy('r.codigo')
            ->pluck('r.codigo')
            ->all();

        $this->assertSame(['ADMIN_CONTRATO', 'COMPRAS_ANALISTA', 'UNIDAD_REQUIRENTE'], $roles);
    }

    public function test_solo_el_administrador_ve_modulos_de_administracion(): void
    {
        $roles = DB::table('rol_permiso as rp')
            ->join('roles as r', 'r.id', '=', 'rp.rol_id')
            ->join('permisos as p', 'p.id', '=', 'rp.permiso_id')
            ->whereIn('p.modulo', ['usuarios', 'roles', 'permisos', 'catalogos', 'flujos', 'parametros', 'auditoria'])
            ->distinct()
            ->pluck('r.codigo')
            ->all();

        $this->assertSame(['ADMIN'], $roles);
    }

    public function test_el_codigo_del_proceso_lo_genera_solo_el_analista_de_compras(): void
    {
        $roles = DB::table('rol_permiso as rp')
            ->join('roles as r', 'r.id', '=', 'rp.rol_id')
            ->join('permisos as p', 'p.id', '=', 'rp.permiso_id')
            ->where('p.codigo', 'procesos.asignar_codigo')
            ->pluck('r.codigo')
            ->all();

        $this->assertSame(['COMPRAS_ANALISTA'], $roles);
    }

    public function test_parametros_del_codigo_de_proceso(): void
    {
        $this->assertSame('AILEP', DB::table('parametros')->where('clave', 'prefijo_institucion')->value('valor'));
        $this->assertSame('PE', DB::table('tipos_proceso')->where('codigo', 'ABASTECIMIENTO_CENTRALIZADO')->value('prefijo'));
        $this->assertTrue((bool) DB::table('estados_proceso')->where('codigo', 'PARA_PUBLICAR')->value('permite_asignar_codigo'));
    }
}
