<?php

namespace Tests\Feature;

use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\TiposDocumento\Pages\CreateTipoDocumento;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PanelAdministracionTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_raiz_redirige_al_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_el_administrador_ve_usuarios_roles_y_permisos(): void
    {
        $this->actingAs(User::factory()->conRoles('ADMIN')->create());

        $this->get('/admin/users')->assertOk();
        $this->get('/admin/roles')->assertOk();
        $this->get('/admin/permisos')->assertOk();
    }

    public function test_el_analista_de_compras_no_accede_a_administracion(): void
    {
        $this->actingAs(User::factory()->conRoles('COMPRAS_ANALISTA')->create());

        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/roles')->assertForbidden();
    }

    public function test_el_administrador_crea_un_usuario_con_rol(): void
    {
        $this->actingAs(User::factory()->conRoles('ADMIN')->create());
        $rol = Rol::where('codigo', 'COMPRAS_ANALISTA')->firstOrFail();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Ana Prueba',
                'email' => 'ana@ejemplo.local',
                'cedula' => '1712345678',
                'password' => 'clave-segura-1',
                'activo' => true,
                'roles' => [$rol->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $usuario = User::where('email', 'ana@ejemplo.local')->firstOrFail();
        $this->assertTrue($usuario->tieneRol('COMPRAS_ANALISTA'));
    }

    public function test_un_rol_de_sistema_no_se_puede_eliminar(): void
    {
        $admin = User::factory()->conRoles('ADMIN')->create();
        $this->assertFalse($admin->can('delete', Rol::where('codigo', 'ADMIN')->first()));
    }

    public function test_tipos_de_documento_y_documentos_obligatorios_son_solo_del_administrador(): void
    {
        foreach (['COMPRAS_JEFE', 'COMPRAS_ANALISTA', 'UNIDAD_REQUIRENTE'] as $rol) {
            $this->actingAs(User::factory()->conRoles($rol)->create());
            $this->get('/admin/tipos-documento')->assertForbidden();
            $this->get('/admin/documentos-obligatorios')->assertForbidden();
        }

        $this->actingAs(User::factory()->conRoles('ADMIN')->create());
        $this->get('/admin/tipos-documento')->assertOk();
        $this->get('/admin/documentos-obligatorios')->assertOk();
    }

    public function test_el_administrador_crea_un_tipo_de_documento(): void
    {
        $this->actingAs(User::factory()->conRoles('ADMIN')->create());

        Livewire::test(CreateTipoDocumento::class)
            ->fillForm([
                'codigo' => 'CERTIFICADO_PRUEBA',
                'nombre' => 'Certificado de prueba',
                'extensiones_permitidas' => ['pdf'],
                'tamano_maximo_mb' => 10,
                'requiere_revision' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tipos_documento', [
            'codigo' => 'CERTIFICADO_PRUEBA', 'extensiones_permitidas' => 'pdf', 'permite_multiples' => false,
        ]);
    }
}
