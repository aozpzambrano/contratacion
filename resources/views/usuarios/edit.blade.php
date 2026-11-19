@extends('layouts.app')
@section('content')
<h1>Editar usuario</h1>
<form method="POST" action="{{ route('usuarios.update', $usuario) }}">
  @method('PUT')
  @include('usuarios._form')
</form>
@endsection
