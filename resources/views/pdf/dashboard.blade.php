<!doctype html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#1f2937} h1{font-size:18px;margin:0} .muted{color:#6b7280}
table{width:100%;border-collapse:collapse;margin-top:16px} th,td{padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left} th{font-size:11px;color:#6b7280}
</style></head><body>
<h1>{{ $team }}: dashboard</h1>
<p class="muted">{{ $period['label'] }}: {{ $period['from'] }} to {{ $period['to'] }}. Compared with the previous period of the same length.</p>
<table><thead><tr><th>Measure</th><th>This period</th><th>Previous</th><th>Change</th></tr></thead><tbody>
@foreach ($cards as $c)
<tr><td>{{ $c['label'] }}</td><td>{{ $c['shown'] }}</td><td>{{ $c['before'] }}</td><td>{{ $c['change'] !== null ? ($c['change'] > 0 ? '+' : '').$c['change'].'%' : '' }}{{ $c['small'] ? ' (small numbers)' : '' }}</td></tr>
@endforeach
</tbody></table>
<p class="muted">Team to Team Referrals · generated {{ now()->toDayDateTimeString() }}</p>
</body></html>
