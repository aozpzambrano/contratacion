@php($zip = $getState())
<div style="font-size:.875rem;font-weight:500;margin-bottom:4px;">Oferta del proveedor</div>
@if (filled($zip))
    <a href="{{ $zip['url'] }}" target="_blank"
       style="display:inline-block;padding:6px 14px;border-radius:8px;background:#d97706;color:#fff;font-weight:600;text-decoration:none;">
        Descargar oferta (ZIP)
    </a>
    <span style="opacity:.7;margin-left:8px;">{{ $zip['archivos'] }} archivo(s)</span>
@else
    <span style="opacity:.7;">-</span>
@endif
