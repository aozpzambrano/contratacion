<?php

namespace App\Policies;

use App\Models\User;

/** Los permisos son un contrato con el codigo: solo se consultan, no se crean ni editan desde la interfaz. */
class PermisoPolicy extends PoliticaPorPermiso
{
    protected function modulo(): string
    {
        return 'permisos';
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, mixed $registro = null): bool
    {
        return false;
    }

    public function delete(User $user, mixed $registro = null): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
