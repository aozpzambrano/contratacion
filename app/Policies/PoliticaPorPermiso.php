<?php

namespace App\Policies;

use App\Models\User;

/**
 * Base: traduce las acciones de Laravel/Filament a permisos "modulo.accion".
 * Las subclases solo declaran el modulo.
 */
abstract class PoliticaPorPermiso
{
    abstract protected function modulo(): string;

    protected function puede(User $user, string $accion): bool
    {
        return $user->tienePermiso($this->modulo().'.'.$accion);
    }

    public function viewAny(User $user): bool
    {
        return $this->puede($user, 'ver');
    }

    public function view(User $user, mixed $registro = null): bool
    {
        return $this->puede($user, 'ver');
    }

    public function create(User $user): bool
    {
        return $this->puede($user, 'crear');
    }

    public function update(User $user, mixed $registro = null): bool
    {
        return $this->puede($user, 'editar');
    }

    public function delete(User $user, mixed $registro = null): bool
    {
        return $this->puede($user, 'eliminar');
    }

    public function deleteAny(User $user): bool
    {
        return $this->puede($user, 'eliminar');
    }
}
