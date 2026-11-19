@csrf
@php($marcados = old('permisos', $rol?->permissions->pluck('name')->all() ?? []))
<p><label>Nombre del rol <input name="name" value="{{ old('name', $rol?->name) }}" required></label></p>
@foreach ($grupos as $recurso => $permisos)
  <fieldset>
    <legend>{{ $recurso }}</legend>
    @foreach ($permisos as $p)
      <label><input type="checkbox" name="permisos[]" value="{{ $p->name }}" @checked(in_array($p->name, $marcados))> {{ Str::afterLast($p->name, '.') }}</label>
    @endforeach
  </fieldset>
@endforeach
@if ($errors->any())
  <ul style="color:red">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
@endif
<button type="submit">Guardar</button>