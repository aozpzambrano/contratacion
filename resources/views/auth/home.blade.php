@extends('layouts.app')
@section('content')
<h1>Bienvenido, {{ auth()->user()->name }}</h1>
<p>Roles: {{ auth()->user()->getRoleNames()->join(', ') ?: 'sin roles asignados' }}</p>
@endsection
