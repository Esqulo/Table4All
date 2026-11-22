@props(['url'])
{{-- Brand logo instead of the app name in text. PNG on purpose: Gmail and Outlook don't show SVG.
     The URL is absolute (APP_URL), since mail clients fetch it from the internet. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('images/brand/email-logo.png') }}" class="logo" width="167" height="48" alt="{{ config('app.name') }}">
</a>
</td>
</tr>
