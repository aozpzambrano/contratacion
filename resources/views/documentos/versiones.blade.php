@php($servicio = app(\App\Services\ServicioDocumento::class))
@php($puedeDescargar = $servicio->puedeDescargar($documento, auth()->user()))
@php($actualId = $documento->version_actual_id)
<div class="space-y-2">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left">
                <th class="py-1 pe-3">Versión</th>
                <th class="py-1 pe-3">Archivo</th>
                <th class="py-1 pe-3">Cargado por</th>
                <th class="py-1 pe-3">Fecha</th>
                <th class="py-1">Comentario</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($documento->versiones()->with(['archivo', 'usuario'])->get() as $version)
                <tr class="border-t">
                    <td class="py-1 pe-3">v{{ $version->numero_version }}</td>
                    <td class="py-1 pe-3">
                        @if ($puedeDescargar && $version->id === $actualId)
                            <a class="underline" href="{{ route('documentos.descargar', $version) }}">{{ $version->archivo->nombre_original }}</a>
                        @else
                            {{ $version->archivo->nombre_original }}
                        @endif
                        <span class="text-xs opacity-70">({{ number_format($version->archivo->tamano_bytes / 1048576, 2) }} MB)</span>
                        @if ($version->id === $actualId)
                            <span class="text-xs font-semibold">· vigente</span>
                        @else
                            <span class="text-xs opacity-70">· solo registro de respaldo</span>
                        @endif
                    </td>
                    <td class="py-1 pe-3">{{ $version->usuario->name }}</td>
                    <td class="py-1 pe-3">{{ $version->created_at->setTimezone('America/Guayaquil')->format('d/m/Y H:i') }}</td>
                    <td class="py-1">{{ $version->comentario ?? '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
