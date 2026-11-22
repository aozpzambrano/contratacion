<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy extends PoliticaPorPermiso
{
    protected function modulo(): string
    {
        return 'usuarios';
    }

    /**
     * Un usuario con historial no puede borrarse (las tablas de historial lo referencian):
     * se desactiva con el campo "activo". Nadie puede eliminarse a si mismo.
     */
    public function delete(User $user, mixed $registro = null): bool
    {
        return parent::delete($user, $registro) && $registro?->id !== $user->id;
    }
}
