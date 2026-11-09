<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $title }}</title>
<style>
  @page { margin: 56px 56px 64px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0E1B3D; line-height: 1.55; }
  h1 { font-size: 20px; margin: 0 0 4px; } .muted { color: #5B6785; }
  .bar { height: 4px; background: #2451F5; margin: 14px 0 18px; } .bar span { display: block; width: 50%; height: 4px; background: #FF7A1A; margin-left: 50%; }
  .summary { background: #EEF2FF; border-radius: 6px; padding: 12px 14px; margin-bottom: 18px; } .summary h2 { font-size: 12px; margin: 0 0 6px; }
  .summary li { margin-bottom: 3px; } h3 { font-size: 12px; margin: 16px 0 4px; }
  .wm { position: fixed; top: 45%; left: 8%; font-size: 48px; color: rgba(36,81,245,.08); transform: rotate(-24deg); }
</style></head>
<body>
@isset($watermark)<div class="wm">{{ $watermark }}</div>@endisset
<h1>{{ $title }}</h1>
<div class="muted">{{ $industry->name }} · Team to Team Referrals</div>
<div class="bar"><span></span></div>
<div class="summary"><h2>In plain English</h2><ul>@foreach ($summary as $line)<li>{{ $line }}</li>@endforeach</ul></div>
@foreach ($sections as $i => $s)
  <h3>{{ $i + 1 }}. {{ $s['title'] }}</h3>
  <p>{{ $s['text'] }}</p>
@endforeach
</body></html>
