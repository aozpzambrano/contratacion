<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolController extends Controller
{
    public function index()
    {
        $roles = Role::withCount(['permissions', 'users'])->orderBy('name')->get();
        return view('roles.index', compact('roles'));
    }

    public function create()
    {
        return view('roles.create', ['grupos' => $this->grupos()]);
    }

    public function store(Request $request)
    {
        $request->merge(['name' => Str::upper(trim((string) $request->name))]);
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:60', 'unique:roles,name'],
            'permisos' => ['array'],
            'permisos.*' => ['exists:permissions,name'],
        ]);

        $rol = Role::create(['name' => $datos['name'], 'guard_name' => 'web']);
        $rol->syncPermissions($datos['permisos'] ?? []);

        return redirect()->route('roles.index')->with('ok', 'Rol creado.');
    }

    public function edit(Role $rol)
    {
        abort_if($rol->name === 'ADMIN', 403, 'El rol ADMIN no se puede modificar.');
        return view('roles.edit', ['rol' => $rol, 'grupos' => $this->grupos()]);
    }

    public function update(Request $request, Role $rol)
    {
        abort_if($rol->name === 'ADMIN', 403, 'El rol ADMIN no se puede modificar.');

        $request->merge(['name' => Str::upper(trim((string) $request->name))]);
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->ignore($rol->id)],
            'permisos' => ['array'],
            'permisos.*' => ['exists:permissions,name'],
        ]);

        $rol->update(['name' => $datos['name']]);
        $rol->syncPermissions($datos['permisos'] ?? []);

        return redirect()->route('roles.index')->with('ok', 'Rol actualizado.');
    }

    // Agrupa los permisos por recurso: "adm.usuarios.ver" → grupo "adm.usuarios"
    private function grupos()
    {
        return Permission::orderBy('name')->get()
            ->groupBy(fn ($p) => Str::beforeLast($p->name, '.'));
    }
}
