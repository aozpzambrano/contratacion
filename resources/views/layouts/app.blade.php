
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('title', 'Contratación Pública')</title>
</head>
<body>
<nav>
  @auth
    <a href="{{ route('home') }}">Inicio</a>
    @can('adm.usuarios.ver') <a href="{{ route('usuarios.index') }}">Usuarios</a> @endcan
    @can('adm.roles.ver') <a href="{{ route('roles.index') }}">Roles</a> @endcan
    @can('rep.usuarios.exportar') <a href="{{ route('reportes.usuarios.csv') }}">Reporte CSV</a> @endcan
    <form method="POST" action="{{ route('logout') }}" style="display:inline">
      @csrf
      <button type="submit">Salir ({{ auth()->user()->name }})</button>
    </form>
  @endauth
</nav>
@if (session('ok')) <p style="color:green">{{ session('ok') }}</p> @endif
<main>@yield('content')</main>
</body>
</html>
