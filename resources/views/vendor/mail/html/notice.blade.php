{{-- Aviso destacado (ámbar): lo que la persona tiene que hacer o saber. --}}
<table class="notice-table" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="notice">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
