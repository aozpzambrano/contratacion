<?php

namespace App\Http\Controllers;

use App\Models\DocumentoVersion;
use App\Services\ServicioDocumento;
use Illuminate\Http\Request;

class DescargaDocumentoController
{
    public function __invoke(Request $request, DocumentoVersion $version, ServicioDocumento $servicio)
    {
        $usuario = $request->user();

        if ($usuario === null) {
            return redirect('/admin/login');
        }

        return $servicio->descargar($version, $usuario);
    }
}
