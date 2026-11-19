@csrf
@php($rolesUsuario = old('roles', $usuario?->roles->pluck('name')->all() ?? []))
<p><label>Nombre <input name="name" value="{{ old('name', $usuario?->name) }}" required></label></p>
<p><label>Correo <input type="email" name="email" value="{{ old('email', $usuario?->email) }}" required></label></p>
<p><label>Cédula <input name="cedula" value="{{ old('cedula', $usuario?->cedula) }}"></label></p>
<p><label>Cargo <input name="cargo" value="{{ old('cargo', $usuario?->cargo) }}"></label></p>
<p><label>Unidad <input name="unidad" value="{{ old('unidad', $usuario?->unidad) }}"></label></p>
<p><label>Contraseña {{ $usuario ? '(vacío = no cambiar)' : '' }}
  <input type="password" name="password" @required(! $usuario)></label></p>
<p><label>Confirmar contraseña <input type="password" name="password_confirmation"></label></p>
<p><label><input type="checkbox" name="activo" value="1" @checked(old('activo', $usuario?->activo ?? true))> Activo</label></p>
<fieldset>
  <legend>Roles</legend>
  @foreach ($roles as $rol)
    <label><input type="checkbox" name="roles[]" value="{{ $rol->name }}" @checked(in_array($rol->name, $rolesUsuario))> {{ $rol->name }}</label>
  @endforeach
</fieldset>
@if ($errors->any())
  <ul style="color:red">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
@endif
<button type="submit">Guardar</button>