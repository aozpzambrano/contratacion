@extends('layouts.app')
@section('content')
<h1>Ingreso al sistema</h1>
<form method="POST" action="{{ route('login') }}">
  @csrf
  <p><label>Correo <input type="email" name="email" value="{{ old('email') }}" required></label></p>
  <p><label>Contraseña <input type="password" name="password" required></label></p>
  @error('email') <p style="color:red">{{ $message }}</p> @enderror
  <button type="submit">Ingresar</button>
</form>
@endsection