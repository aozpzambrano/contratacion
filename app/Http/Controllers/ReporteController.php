<?php

namespace App\Http\Controllers;

use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReporteController extends Controller
{
    public function usuariosCsv(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['nombre', 'email', 'cargo', 'unidad', 'roles', 'activo'], ',', '"', '');
            User::with('roles')->orderBy('name')->each(function ($u) use ($out) {
                fputcsv($out, [
                    $u->name, $u->email, $u->cargo, $u->unidad,
                    $u->roles->pluck('name')->join('; '),
                    $u->activo ? 'Sí' : 'No',
                ], ',', '"', '');
            });
            fclose($out);
        }, 'usuarios.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
