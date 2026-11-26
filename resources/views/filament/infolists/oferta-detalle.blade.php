@php
    $items = (array) $getState();
    $celda = 'padding:6px 10px;border-bottom:1px solid rgba(128,128,128,.3);';
    $cabecera = 'padding:6px 10px;border-bottom:2px solid rgba(128,128,128,.5);font-weight:600;';
@endphp
<div style="font-size:.875rem;font-weight:500;margin-bottom:4px;">Detalle por ítem</div>
<table style="width:100%;border-collapse:collapse;font-size:.875rem;">
    <thead>
        <tr>
            <th style="{{ $cabecera }}text-align:left;">Ítem</th>
            <th style="{{ $cabecera }}text-align:right;">Cantidad</th>
            <th style="{{ $cabecera }}text-align:right;">Valor unitario</th>
            <th style="{{ $cabecera }}text-align:right;">Total del ítem</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($items as $i)
            <tr>
                <td style="{{ $celda }}">#{{ $i['linea'] }} {{ $i['descripcion'] }}</td>
                <td style="{{ $celda }}text-align:right;">{{ number_format($i['cantidad']) }}</td>
                <td style="{{ $celda }}text-align:right;">$ {{ number_format($i['precio'], 4, '.', ',') }}</td>
                <td style="{{ $celda }}text-align:right;">$ {{ number_format($i['total'], 2, '.', ',') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
