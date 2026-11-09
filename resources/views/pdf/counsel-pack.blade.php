<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Counsel review pack</title>
<style>
  @page { margin: 56px; } body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0E1B3D; line-height: 1.55; }
  h1 { font-size: 20px; margin: 0; } h2 { font-size: 14px; margin: 22px 0 6px; border-bottom: 1px solid #DDE3F0; padding-bottom: 4px; } h3 { font-size: 12px; margin: 12px 0 2px; }
  .muted { color: #5B6785; } table { width: 100%; border-collapse: collapse; } td, th { text-align: left; padding: 4px 6px; border-bottom: 1px solid #EEF1F7; vertical-align: top; }
  .box { background: #F6F7FB; padding: 10px 12px; border-radius: 6px; }
</style></head>
<body>
<h1>Counsel review pack: {{ $industry->name }}</h1>
<div class="muted">Tier {{ $industry->tier->value }} ({{ $industry->tier->label() }}) · Draft version {{ $version ?? 'default wording' }} · {{ $approved ? 'Approved' : 'Awaiting approval' }} · Generated {{ $generated }}</div>
<h2>1. Plain-English summary shown to both parties</h2>
<div class="box"><ul>@foreach ($summary as $line)<li>{{ $line }}</li>@endforeach</ul></div>
<h2>2. Agreement wording (sample data)</h2>
@foreach ($sections as $i => $s)<h3>{{ $i + 1 }}. {{ $s['title'] }}</h3><p>{{ $s['text'] }}</p>@endforeach
<h2>3. State addenda</h2>
@forelse ($addenda as $a)<h3>{{ $a['name'] }}</h3><p>{{ $a['text'] }}</p>@empty<p class="muted">No state addenda yet.</p>@endforelse
<h2>4. Messages members and clients receive</h2>
<p class="muted">Terms used: a {{ $terms['pro'] }} serves a {{ $terms['client'] }}; a {{ $terms['referral'] }}{{ $industry->tier->allowsMemberReferralFees() ? '; fee called a '.$terms['fee'] : '' }}. Sample data shown.</p>
<table><tr><th style="width:24%">Notification</th><th style="width:10%">Channel</th><th>Wording</th></tr>
@foreach ($messages as $m)<tr><td>{{ $m['label'] }}<br><span class="muted">{{ $m['source'] === 'industry' ? 'Industry wording' : ($m['source'] === 'platform' ? 'Platform wording' : 'Default wording') }}</span></td><td>{{ $m['channel'] }}</td><td>@if ($m['subject'])<strong>{{ $m['subject'] }}</strong><br>@endif{{ $m['body'] }}</td></tr>@endforeach
</table>
<h2>5. Version and approval history</h2>
<table><tr><th>Version</th><th>Saved</th><th>Approved</th><th>Reviewed by</th><th>Note</th></tr>
@forelse ($history as $h)<tr><td>v{{ $h['version'] }}{{ $h['active'] ? ' (draft)' : '' }}</td><td>{{ $h['when'] }}</td><td>{{ $h['approvedAt'] ?? '—' }}</td><td>{{ $h['counsel'] ?? '—' }}</td><td>{{ $h['note'] ?? '' }}</td></tr>
@empty<tr><td colspan="5" class="muted">Default wording, never saved.</td></tr>@endforelse
</table>
<p class="muted" style="margin-top:18px">To approve: review sections 1–4, then record your name, firm and any conditions. Approval applies to this exact version only.</p>
</body></html>
