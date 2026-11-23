<?php

namespace App\Policies;

use App\Models\User;

/**
 * Catalogo de tipos de documento: solo el administrador (quien tiene catalogos.editar).
 * Aunque otros roles tengan catalogos.ver, aqui ni siquiera se listan.
 */
class TipoDocumentoPolicy extends PoliticaPorPermiso
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
        return $this->puede($user, 'editar');
    }

    public function update(User $user, mixed $registro = null): bool
    {
        return $this->puede($user, 'editar');
    }

    /** Los tipos tienen documentos asociados: se desactivan, no se eliminan. */
    public function delete(User $user, mixed $registro = null): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
