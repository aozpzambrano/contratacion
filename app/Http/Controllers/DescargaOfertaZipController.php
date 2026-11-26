<?php

namespace App\Http\Controllers;

use App\Services\ServicioOferta;
use Illuminate\Http\Request;

class DescargaOfertaZipController
{
    public function __invoke(Request $request, int $oferta, ServicioOferta $servicio)
    {
        $usuario = $request->user();

        if ($usuario === null) {
            return redirect('/admin/login');
        }

        return $servicio->descargarOfertaZip($oferta, $usuario);
    }
}
