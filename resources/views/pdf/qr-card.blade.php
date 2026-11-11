<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $team->name }}</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; color: #0E1B3D; text-align: center; margin: 0; }
  .bar { height: 8px; background: #2451F5; } h1 { font-size: 18px; margin: 18px 10px 4px; } .muted { color: #5B6785; font-size: 11px; }
  img { width: 62%; margin: 12px auto; } .cta { font-size: 14px; font-weight: bold; margin-top: 4px; }
</style></head><body>
<div class="bar"></div>
<h1>{{ $team->name }}</h1>
<div class="cta">Refer a client to us</div>
<img src="data:image/svg+xml;base64,{{ $qr }}" alt="">
<div class="muted">{{ $url }}</div>
<div class="muted" style="margin-top:8px">Team to Team Referrals · verified pros, one per city</div>
</body></html>
