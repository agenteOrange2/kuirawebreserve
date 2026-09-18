{{-- Mismo panel del framework, con la barra lateral en el color del hotel
     (/reservas/ajustes → Apariencia) en vez del negro de fábrica. --}}
@php($brand = \App\Mail\TenantBranding::resolve())
<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-left: {{ $brand->accent }} solid 4px; margin: 21px 0;">
<tr>
<td class="panel-content">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="panel-item">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
</td>
</tr>
</table>
