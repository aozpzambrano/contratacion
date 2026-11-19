<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesPermisosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Catálogo de formularios y reportes con sus acciones (recurso.accion).
        // Cada fase nueva agrega aquí sus recursos y se vuelve a ejecutar el seeder.
        $recursos = [
            'adm.usuarios' => ['ver', 'crear', 'editar'],
            'adm.roles'    => ['ver', 'crear', 'editar'],
            'rep.usuarios' => ['ver', 'exportar'],
        ];

        $todos = [];
        foreach ($recursos as $recurso => $acciones) {
            foreach ($acciones as $accion) {
                $todos[] = Permission::findOrCreate("$recurso.$accion", 'web')->name;
            }
        }

        Role::findOrCreate('ADMIN', 'web')->syncPermissions($todos);
        Role::findOrCreate('CONSULTA', 'web')->syncPermissions(['rep.usuarios.ver']);

        $password = env('ADMIN_PASSWORD');
        if (! $password) {
            throw new \RuntimeException('Define ADMIN_PASSWORD en el archivo .env');
        }

        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@ejemplo.gob.ec')],
            ['name' => 'Administrador', 'password' => Hash::make($password)]
        );
        $admin->syncRoles(['ADMIN']);
    }
}
