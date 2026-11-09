<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Tier {{ $tier->value }} counsel review pack</title>
<style>
  @page { margin: 56px; } body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #0E1B3D; line-height: 1.5; }
  h1 { font-size: 20px; margin: 0; } h2 { font-size: 15px; margin: 0 0 4px; } h3 { font-size: 12px; margin: 14px 0 4px; border-bottom: 1px solid #DDE3F0; padding-bottom: 3px; } h4 { font-size: 11px; margin: 8px 0 1px; }
  .muted { color: #5B6785; } table { width: 100%; border-collapse: collapse; } td, th { text-align: left; padding: 3px 5px; border-bottom: 1px solid #EEF1F7; vertical-align: top; }
  .box { background: #F6F7FB; padding: 8px 10px; border-radius: 6px; } .page { page-break-before: always; }
</style></head>
<body>
<h1>Counsel review pack: Tier {{ $tier->value }} ({{ $tier->label() }})</h1>
<p class="muted">{{ $industries->count() }} industries · Generated {{ $generated }} · Sample data throughout.</p>
<ol>@foreach ($industries as $x)<li>{{ $x['industry']->name }} — {{ $x['approved'] ? 'agreement approved' : 'agreement not approved' }}</li>@endforeach</ol>
@foreach ($industries as $x)
<div class="page">
  <h2>{{ $x['industry']->name }}</h2>
  <p class="muted">A {{ $x['terms']['pro'] }} serves a {{ $x['terms']['client'] }}; a referral is called a{{ in_array(strtolower(substr($x['terms']['referral'], 0, 1)), ['a','e','i','o','u']) ? 'n' : '' }} {{ $x['terms']['referral'] }}. Draft {{ $x['version'] ? 'v'.$x['version'] : 'default wording' }}.</p>
  @if ($tier->value === 'C')
    <p class="box">Directory only: no referral agreement. Members are listed and contacted directly; only the client’s consent is recorded.</p>
  @else
    <h3>Plain-English summary</h3><div class="box"><ul>@foreach ($x['summary'] as $line)<li>{{ $line }}</li>@endforeach</ul></div>
    <h3>Agreement wording</h3>@foreach ($x['sections'] as $i => $s)<h4>{{ $i + 1 }}. {{ $s['title'] }}</h4><p>{{ $s['text'] }}</p>@endforeach
    <h3>State addenda</h3>@forelse ($x['addenda'] as $a)<h4>{{ $a['name'] }}</h4><p>{{ $a['text'] }}</p>@empty<p class="muted">None yet.</p>@endforelse
  @endif
  <h3>Messages</h3>
  <table><tr><th style="width:26%">Notification</th><th style="width:9%">Channel</th><th>Wording</th></tr>
  @foreach ($x['messages'] as $m)<tr><td>{{ $m['label'] }}</td><td>{{ $m['channel'] }}</td><td>@if ($m['subject'])<strong>{{ $m['subject'] }}</strong><br>@endif{{ $m['body'] }}</td></tr>@endforeach
  </table>
</div>
@endforeach
</body></html>
