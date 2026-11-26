@php
    $filas = (array) $getState();
    $celda = 'padding:6px 10px;border-bottom:1px solid rgba(128,128,128,.3);vertical-align:top;';
    $cabecera = 'padding:6px 10px;border-bottom:2px solid rgba(128,128,128,.5);font-weight:600;text-align:left;';
    $fecha = fn ($f) => $f ? $f->copy()->setTimezone('America/Guayaquil')->format('d/m/Y H:i') : '-';
@endphp
<div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
        <thead>
            <tr>
                <th style="{{ $cabecera }}">N.°</th>
                <th style="{{ $cabecera }}">Necesidad de Contratación</th>
                <th style="{{ $cabecera }}">Fecha de Publicación</th>
                <th style="{{ $cabecera }}">Fecha Límite</th>
                <th style="{{ $cabecera }}">Link de Publicación</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($filas as $p)
                <tr>
                    <td style="{{ $celda }}">{{ $p['secuencia'] }}</td>
                    <td style="{{ $celda }}">{{ $p['codigo_nc'] }}</td>
                    <td style="{{ $celda }}white-space:nowrap;">{{ $fecha($p['fecha_publicacion']) }}</td>
                    <td style="{{ $celda }}white-space:nowrap;">{{ $fecha($p['fecha_limite']) }}</td>
                    <td style="{{ $celda }}word-break:break-all;">
                        @if (filled($p['enlace']))
                            <a href="{{ $p['enlace'] }}" target="_blank" rel="noopener" style="text-decoration:underline;">{{ \Illuminate\Support\Str::limit($p['enlace'], 50) }}</a>
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
