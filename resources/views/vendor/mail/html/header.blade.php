@props(['url'])
{{-- Sin el caso especial del logo de Laravel: aquí el slot ya trae el logo
     del hotel o su nombre (ver vendor/mail/html/message.blade.php). --}}
<tr>
<td class="header" style="padding: 28px 0 20px; text-align: center;">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
{!! $slot !!}
</a>
</td>
</tr>
