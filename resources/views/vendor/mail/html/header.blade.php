@props(['url'])
{{-- Every email opens with the website's logo (a PNG: email apps don't show SVG). The name is its alt text, so it
     still reads "Team to Team Referrals" when images are blocked and for screen readers. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ rtrim((string) config('app.url'), '/') }}/brand/email-logo.png" width="200" height="48" alt="{{ trim(strip_tags((string) $slot)) ?: config('app.name') }}" style="display: block; width: 200px; height: 48px; max-width: 100%; border: 0;">
</a>
</td>
</tr>
