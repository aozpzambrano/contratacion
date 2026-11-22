<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Crea (o actualiza) el administrador inicial con las variables ADMIN_* del .env.
 * Los roles y permisos ya los carga la migracion del esquema.
 */
class UsuarioAdministradorSeeder extends Seeder
{
    public function run(): void
    {
        $correo = env('ADMIN_EMAIL');
        $clave = env('ADMIN_PASSWORD');

        if (blank($correo) || blank($clave)) {
            throw new RuntimeException('Defina ADMIN_EMAIL y ADMIN_PASSWORD en el .env antes de ejecutar el seeder.');
        }

        $rol = Rol::where('codigo', 'ADMIN')->firstOrFail();

        $usuario = User::updateOrCreate(
            ['email' => $correo],
            ['name' => env('ADMIN_NOMBRE', 'Administrador del Sistema'), 'password' => $clave, 'activo' => true],
        );

        $usuario->roles()->syncWithoutDetaching([$rol->id]);
    }
}
