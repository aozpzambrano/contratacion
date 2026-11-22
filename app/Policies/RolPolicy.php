<?php

namespace App\Policies;

use App\Models\User;

class RolPolicy extends PoliticaPorPermiso
{
    protected function modulo(): string
    {
        return 'roles';
    }

    /** Los roles de sistema (es_sistema) no se eliminan. */
    public function delete(User $user, mixed $registro = null): bool
    {
        return parent::delete($user, $registro) && ! ($registro?->es_sistema ?? false);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
