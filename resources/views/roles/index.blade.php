@extends('layouts.app')
@section('content')
<h1>Roles</h1>
@can('adm.roles.crear') <a href="{{ route('roles.create') }}">Nuevo rol</a> @endcan
<table border="1" cellpadding="4">
  <tr><th>Rol</th><th>Permisos</th><th>Usuarios</th><th></th></tr>
  @foreach ($roles as $r)
    <tr>
      <td>{{ $r->name }}</td><td>{{ $r->permissions_count }}</td><td>{{ $r->users_count }}</td>
      <td>@if ($r->name !== 'ADMIN') @can('adm.roles.editar') <a href="{{ route('roles.edit', $r) }}">Editar</a> @endcan @endif</td>
    </tr>
  @endforeach
</table>
@endsection