<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UsuarioController extends Controller
{
    public function index()
    {
        $usuarios = User::with('roles')->orderBy('name')->paginate(25);
        return view('usuarios.index', compact('usuarios'));
    }

    public function create()
    {
        return view('usuarios.create', ['roles' => Role::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate($this->reglas());
        $this->soloAdminAsignaAdmin($request, $datos['roles'] ?? []);

        // El modelo User de Laravel 11+ encripta 'password' automáticamente
        $usuario = User::create([
            ...Arr::except($datos, 'roles'),
            'activo' => $request->boolean('activo'),
        ]);
        $usuario->syncRoles($datos['roles'] ?? []);

        return redirect()->route('usuarios.index')->with('ok', 'Usuario creado.');
    }

    public function edit(User $usuario)
    {
        return view('usuarios.edit', [
            'usuario' => $usuario,
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $usuario)
    {
        $datos = $request->validate($this->reglas($usuario));
        $this->soloAdminAsignaAdmin($request, $datos['roles'] ?? []);

        $datos['activo'] = $request->boolean('activo');
        if ($usuario->is($request->user()) && ! $datos['activo']) {
            return back()->withErrors(['activo' => 'No puedes desactivar tu propio usuario.'])->withInput();
        }
        if (empty($datos['password'])) {
            unset($datos['password']);   // vacío = no cambiar la contraseña
        }

        $usuario->update(Arr::except($datos, 'roles'));
        $usuario->syncRoles($datos['roles'] ?? []);

        return redirect()->route('usuarios.index')->with('ok', 'Usuario actualizado.');
    }

    private function reglas(?User $usuario = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($usuario)],
            'cedula' => ['nullable', 'string', 'max:13', Rule::unique('users', 'cedula')->ignore($usuario)],
            'cargo' => ['nullable', 'string', 'max:120'],
            'unidad' => ['nullable', 'string', 'max:120'],
            'password' => [$usuario ? 'nullable' : 'required', 'confirmed', Password::min(10)],
            'roles' => ['array'],
            'roles.*' => ['exists:roles,name'],
        ];
    }

    // Evita que alguien sin rol ADMIN se otorgue o asigne privilegios totales
    private function soloAdminAsignaAdmin(Request $request, array $roles): void
    {
        abort_if(in_array('ADMIN', $roles) && ! $request->user()->hasRole('ADMIN'), 403);
    }
}
