<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Certificate</title>
<style>
  @page { margin: 0; } body { font-family: DejaVu Sans, sans-serif; color: #0E1B3D; margin: 0; }
  .frame { margin: 36px; border: 3px solid #2451F5; border-radius: 16px; padding: 56px 64px; height: 470px; position: relative; }
  .bar { height: 6px; background: #2451F5; width: 50%; display: inline-block; } .bar2 { height: 6px; background: #FF7A1A; width: 49%; display: inline-block; }
  h1 { font-size: 30px; margin: 22px 0 6px; } .muted { color: #5B6785; } .name { font-size: 40px; font-weight: bold; margin: 26px 0 8px; }
  .foot { position: absolute; bottom: 48px; left: 64px; right: 64px; font-size: 12px; } .right { float: right; }
</style></head>
<body><div class="frame">
  <div><span class="bar"></span><span class="bar2"></span></div>
  <h1>Certificate of completion</h1>
  <div class="muted">Team to Team Referrals · Network training</div>
  <div class="name">{{ $name }}</div>
  <div>has completed the {{ $industry }} network training: client consent, response deadlines, referral agreements and the industry’s rules, with a score of {{ $score }}%.</div>
  <div class="foot"><span>Completed {{ $date }}</span><span class="right">Training version {{ $version }} · teamtoteamreferrals.com</span></div>
</div></body></html>
