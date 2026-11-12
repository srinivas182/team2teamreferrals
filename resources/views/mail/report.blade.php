<!doctype html>
<html><body style="font-family:Arial,sans-serif;color:#1f2937;font-size:14px">
<h2 style="margin:0 0 4px">{{ $name }}</h2>
<p style="color:#6b7280;margin:0 0 16px">{{ $period }} · {{ number_format($total) }} rows</p>
@if (count($preview))
<table cellpadding="6" style="border-collapse:collapse">
<tr>@foreach ($columns as $c)<th align="left" style="border-bottom:1px solid #e5e7eb;font-size:12px;color:#6b7280">{{ $c }}</th>@endforeach</tr>
@foreach ($preview as $row)<tr>@foreach ($row as $v)<td style="border-bottom:1px solid #f3f4f6">{{ $v }}</td>@endforeach</tr>@endforeach
</table>
@if ($total > count($preview))<p style="color:#6b7280">Showing the first {{ count($preview) }}.</p>@endif
@else
<p>No rows in this period.</p>
@endif
@if ($attached)<p>The full report is attached as a CSV.</p>@endif
@if ($link)<p>This report is large, so it’s ready to download (private to you, for 7 days): <a href="{{ $link }}">download the CSV</a>.</p>@endif
<p style="color:#9ca3af;font-size:12px">Team to Team Referrals · client contact details are never included in reports. Manage this schedule in Reports.</p>
</body></html>
