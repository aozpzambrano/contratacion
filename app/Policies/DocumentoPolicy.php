<?php

namespace App\Policies;

use App\Models\Documento;
use App\Models\Proceso;
use App\Models\User;

class DocumentoPolicy extends PoliticaPorPermiso
{
    protected function modulo(): string
    {
        return 'documentos';
    }

    /** Capas: permiso del rol + el proceso del documento debe ser visible para el usuario. */
    public function view(User $user, mixed $registro = null): bool
    {
        return $this->puede($user, 'ver')
            && $registro instanceof Documento
            && Proceso::visiblesPara($user)->whereKey($registro->proceso_id)->exists();
    }

    // Los documentos se crean, versionan y cambian de estado solo mediante ServicioDocumento.
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
