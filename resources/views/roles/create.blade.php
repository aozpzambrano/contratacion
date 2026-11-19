@extends('layouts.app')
@section('content')
<h1>Nuevo rol</h1>
<form method="POST" action="{{ route('roles.store') }}">
  @include('roles._form', ['rol' => null])
</form>
@endsection
