<?php

namespace App\Providers;

use App\Models\Documento;
use App\Models\Permiso;
use App\Models\Proceso;
use App\Models\RequisitoTransicion;
use App\Models\Rol;
use App\Models\TipoDocumento;
use App\Models\User;
use App\Policies\DocumentoPolicy;
use App\Policies\PermisoPolicy;
use App\Policies\ProcesoPolicy;
use App\Policies\RequisitoTransicionPolicy;
use App\Policies\RolPolicy;
use App\Policies\TipoDocumentoPolicy;
use App\Policies\UserPolicy;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // La BD guarda en UTC (timestamptz); el panel muestra la hora de Ecuador.
        FilamentTimezone::set('America/Guayaquil');

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Rol::class, RolPolicy::class);
        Gate::policy(Permiso::class, PermisoPolicy::class);
        Gate::policy(Proceso::class, ProcesoPolicy::class);
        Gate::policy(Documento::class, DocumentoPolicy::class);
        Gate::policy(TipoDocumento::class, TipoDocumentoPolicy::class);
        Gate::policy(RequisitoTransicion::class, RequisitoTransicionPolicy::class);

        // Habilidades con formato "modulo.accion" (p. ej. documentos.revisar) se resuelven
        // contra los permisos del usuario: @can('documentos.revisar'), $user->can(...), middleware can:...
        Gate::before(function ($user, string $habilidad) {
            if ($user instanceof User && preg_match('/^[a-z_]+\.[a-z_]+$/', $habilidad)) {
                return $user->tienePermiso($habilidad);
            }

            return null;
        });
    }
}
