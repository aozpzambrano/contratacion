@extends('layouts.app')
@section('content')
<h1>Nuevo usuario</h1>
<form method="POST" action="{{ route('usuarios.store') }}">
  @include('usuarios._form', ['usuario' => null])
</form>
@endsection
