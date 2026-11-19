@extends('layouts.app')
@section('content')
<h1>Editar rol</h1>
<form method="POST" action="{{ route('roles.update', $rol) }}">
  @method('PUT')
  @include('roles._form')
</form>
@endsection
