
@extends('layouts.app')
@section('content')
<h1>Usuarios</h1>
@can('adm.usuarios.crear') <a href="{{ route('usuarios.create') }}">Nuevo usuario</a> @endcan
<table border="1" cellpadding="4">
  <tr><th>Nombre</th><th>Correo</th><th>Cargo</th><th>Roles</th><th>Activo</th><th></th></tr>
  @foreach ($usuarios as $u)
    <tr>
      <td>{{ $u->name }}</td><td>{{ $u->email }}</td><td>{{ $u->cargo }}</td>
      <td>{{ $u->roles->pluck('name')->join(', ') }}</td>
      <td>{{ $u->activo ? 'Sí' : 'No' }}</td>
      <td>@can('adm.usuarios.editar') <a href="{{ route('usuarios.edit', $u) }}">Editar</a> @endcan</td>
    </tr>
  @endforeach
</table>
{{ $usuarios->links() }}
@endsection
