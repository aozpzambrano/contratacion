<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'cedula', 'cargo', 'unidad', 'activo', 'debe_cambiar_clave',
    ];

    protected $hidden = ['password', 'remember_token'];

    /** Codigos de permiso ya consultados en esta instancia. */
    private ?Collection $codigosPermisos = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'debe_cambiar_clave' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Rol::class, 'usuario_rol', 'user_id', 'rol_id');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->activo && $this->codigosPermisos()->isNotEmpty();
    }

    /** Permisos efectivos: roles activos + permisos activos. Un usuario inactivo no tiene ninguno. */
    public function codigosPermisos(): Collection
    {
        if ($this->codigosPermisos !== null) {
            return $this->codigosPermisos;
        }
        if (! $this->exists || ! $this->activo) {
            return $this->codigosPermisos = collect();
        }

        return $this->codigosPermisos = DB::table('usuario_rol as ur')
            ->join('roles as r', fn ($j) => $j->on('r.id', '=', 'ur.rol_id')->where('r.activo', true))
            ->join('rol_permiso as rp', 'rp.rol_id', '=', 'r.id')
            ->join('permisos as p', fn ($j) => $j->on('p.id', '=', 'rp.permiso_id')->where('p.activo', true))
            ->where('ur.user_id', $this->id)
            ->distinct()
            ->pluck('p.codigo');
    }

    public function tienePermiso(string $codigo): bool
    {
        return $this->codigosPermisos()->contains($codigo);
    }

    /** Capa 2: el permiso debe ejercerse con un rol que opere en la fase (usa usuario_puede() de la BD). */
    public function tienePermisoEnFase(string $codigo, string $fase): bool
    {
        if (! $this->activo) {
            return false;
        }
        $fila = DB::selectOne(
            'select usuario_puede(?, (select id from permisos where codigo = ?), (select id from fases where codigo = ?)) as ok',
            [$this->id, $codigo, $fase]
        );

        return (bool) ($fila->ok ?? false);
    }

    public function tieneRol(string $codigoRol): bool
    {
        return $this->roles()->where('roles.codigo', $codigoRol)->where('roles.activo', true)->exists();
    }

    /** Fuerza a recalcular los permisos (tras cambiar roles dentro del mismo request). */
    public function olvidarPermisos(): void
    {
        $this->codigosPermisos = null;
    }
}
