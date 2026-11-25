<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Services\ServicioDocumento;
use Illuminate\Http\Request;

class DescargaLegalizadoController
{
    public function __invoke(Request $request, Documento $documento, ServicioDocumento $servicio)
    {
        $usuario = $request->user();

        if ($usuario === null) {
            return redirect('/admin/login');
        }

        return $servicio->descargarLegalizado($documento, $usuario);
    }
}
