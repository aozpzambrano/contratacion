<?php

namespace App\Policies;

use App\Models\User;

/** Documentos fijos por tipo de proceso: solo el administrador (catalogos.editar). Se reemplazan, no se eliminan. */
class DocumentoFijoPolicy extends PoliticaPorPermiso
{
    protected function modulo(): string
    {
        return 'catalogos';
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
        return false;
    }

    public function update(User $user, mixed $registro = null): bool
    {
        return $this->puede($user, 'editar');
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
