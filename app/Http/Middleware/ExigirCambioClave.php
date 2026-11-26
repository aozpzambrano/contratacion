<?php

namespace App\Http\Middleware;

use App\Filament\Pages\CambiarClave;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Mientras el usuario tenga la clave inicial, solo puede cambiarla o cerrar sesion. */
class ExigirCambioClave
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario && $usuario->debe_cambiar_clave) {
            $ruta = (string) $request->route()?->getName();
            $permitida = str_ends_with($ruta, 'pages.'.CambiarClave::getSlug())
                || str_ends_with($ruta, '.auth.logout')
                || str_starts_with($ruta, 'livewire.');

            if (! $permitida) {
                return redirect()->to(CambiarClave::getUrl());
            }
        }

        return $next($request);
    }
}
