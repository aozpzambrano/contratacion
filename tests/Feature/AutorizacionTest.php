<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AutorizacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_gate_resuelve_permisos_por_rol(): void
    {
        $admin = User::factory()->conRoles('ADMIN')->create();
        $analista = User::factory()->conRoles('COMPRAS_ANALISTA')->create();

        $this->assertTrue(Gate::forUser($admin)->allows('usuarios.ver'));
        $this->assertFalse(Gate::forUser($analista)->allows('usuarios.ver'));
        $this->assertTrue(Gate::forUser($analista)->allows('documentos.revisar'));
    }

    public function test_usuario_sin_roles_o_inactivo_no_tiene_permisos(): void
    {
        $sinRol = User::factory()->create();
        $inactivo = User::factory()->inactivo()->conRoles('ADMIN')->create();

        $this->assertFalse($sinRol->tienePermiso('usuarios.ver'));
        $this->assertFalse($inactivo->tienePermiso('usuarios.ver'));
    }

    public function test_rol_inactivo_no_otorga_permisos(): void
    {
        $admin = User::factory()->conRoles('ADMIN')->create();
        $this->assertTrue($admin->tienePermiso('roles.ver'));

        \DB::table('roles')->where('codigo', 'ADMIN')->update(['activo' => false]);
        $admin->olvidarPermisos();

        $this->assertFalse($admin->tienePermiso('roles.ver'));
    }

    public function test_el_permiso_depende_de_la_fase_del_rol(): void
    {
        $adminContrato = User::factory()->conRoles('ADMIN_CONTRATO')->create();

        $this->assertTrue($adminContrato->tienePermisoEnFase('procesos.cerrar', 'CONTRACTUAL'));
        $this->assertFalse($adminContrato->tienePermisoEnFase('procesos.cerrar', 'PREPARATORIA'));
    }

    public function test_acceso_al_panel(): void
    {
        $this->assertTrue(User::factory()->conRoles('CONSULTA')->create()->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')));
        $this->assertFalse(User::factory()->create()->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')));
    }
}
