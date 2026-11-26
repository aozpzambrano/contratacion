<?php

namespace Tests\Feature;

use App\Filament\Pages\CambiarClave;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** La clave inicial (p. ej. la de la Comision Tecnica) debe cambiarse en el primer ingreso. */
class CambioClaveInicialTest extends TestCase
{
    use RefreshDatabase;

    private function comision(bool $debeCambiar): User
    {
        $u = User::factory()->conRoles('COMISION_TECNICA')->create(['email' => 'comision.2026.001@ailep.com', 'debe_cambiar_clave' => $debeCambiar]);
        $u->password = 'comision.2026.001';
        $u->save();

        return $u;
    }

    public function test_con_la_clave_inicial_solo_puede_ir_a_cambiar_la_clave(): void
    {
        $this->actingAs($this->comision(true))
            ->get('/admin')
            ->assertRedirect(CambiarClave::getUrl());

        $this->get(CambiarClave::getUrl())->assertOk();
    }

    public function test_sin_clave_inicial_entra_con_normalidad(): void
    {
        $this->actingAs($this->comision(false))->get('/admin')->assertOk();
    }

    public function test_el_cambio_exige_una_clave_distinta_y_libera_al_usuario(): void
    {
        $u = $this->comision(true);
        $this->actingAs($u);

        // igual al usuario, corta o sin confirmar: se rechaza y sigue pendiente
        foreach ([['comision.2026.001', 'comision.2026.001'], ['corta', 'corta'], ['ClaveSegura#2026', 'otra']] as [$clave, $confirmacion]) {
            Livewire::test(CambiarClave::class)
                ->fillForm(['clave' => $clave, 'clave_confirmation' => $confirmacion])
                ->call('guardar')
                ->assertHasFormErrors(['clave']);
            $this->assertTrue($u->fresh()->debe_cambiar_clave);
        }

        Livewire::test(CambiarClave::class)
            ->fillForm(['clave' => 'ClaveSegura#2026', 'clave_confirmation' => 'ClaveSegura#2026'])
            ->call('guardar')
            ->assertHasNoFormErrors();

        $u = $u->fresh();
        $this->assertFalse($u->debe_cambiar_clave);
        $this->assertTrue(Hash::check('ClaveSegura#2026', $u->password));
        $this->actingAs($u)->get('/admin')->assertOk();
    }
}
