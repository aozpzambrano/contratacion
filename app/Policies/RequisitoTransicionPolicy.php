<?php

namespace App\Policies;

use App\Models\User;

/** Documentos obligatorios por transicion: solo el administrador (flujos.editar). */
class RequisitoTransicionPolicy extends PoliticaPorPermiso
{
    protected function modulo(): string
    {
        return 'flujos';
    }

    public function viewAny(User $user): bool
    {
        return $this->puede($user, 'editar');
    }

    public function view(User $user, mixed $registro = null): bool
    {
        return $this->puede($user, 'editar');
    }

    public function create(User $user): bool
    {
        return $this->puede($user, 'editar');
    }

    public function update(User $user, mixed $registro = null): bool
    {
        return $this->puede($user, 'editar');
    }

    public function delete(User $user, mixed $registro = null): bool
    {
        return $this->puede($user, 'editar');
    }

    public function deleteAny(User $user): bool
    {
        return $this->puede($user, 'editar');
    }
}
