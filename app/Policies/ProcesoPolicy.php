<?php

namespace App\Policies;

use App\Models\Proceso;
use App\Models\User;

class ProcesoPolicy extends PoliticaPorPermiso
{
    protected function modulo(): string
    {
        return 'procesos';
    }

    public function view(User $user, mixed $registro = null): bool
    {
        return $this->puede($user, 'ver') && $this->esVisible($user, $registro);
    }

    /** Capas: permiso del rol + visibilidad del registro + estado que admite correcciones. */
    public function update(User $user, mixed $registro = null): bool
    {
        return $this->puede($user, 'editar')
            && $this->esVisible($user, $registro)
            && $registro?->esEditable();
    }

    /** Los procesos no se eliminan: tienen historial. */
    public function delete(User $user, mixed $registro = null): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function esVisible(User $user, ?Proceso $proceso): bool
    {
        return $proceso !== null
            && Proceso::visiblesPara($user)->whereKey($proceso->getKey())->exists();
    }
}
