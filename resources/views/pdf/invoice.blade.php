<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Invoice {{ $inv->number }}</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #0E1B3D; }
  .bar { height: 6px; background: #2451F5; } .bar2 { height: 6px; background: #FF7A1A; width: 35%; }
  h1 { font-size: 22px; margin: 18px 0 2px; } .muted { color: #5B6785; } table { width: 100%; border-collapse: collapse; margin-top: 18px; }
  th { text-align: left; color: #5B6785; font-weight: normal; padding: 6px 8px; } td { padding: 8px; border-top: 1px solid #E6EAF4; }
  .amt { text-align: right; white-space: nowrap; } .total td { font-weight: bold; font-size: 14px; border-top: 2px solid #0E1B3D; } .free { color: #157F3C; font-size: 11px; }
</style></head><body>
<div class="bar"><div class="bar2"></div></div>
<h1>Invoice {{ $inv->number }}</h1>
<div class="muted">Team to Team Referrals · teamtoteamreferrals.com</div>
<p><strong>Billed to:</strong> {{ $owner }}<br><strong>Period:</strong> {{ \Carbon\Carbon::parse($inv->period.'-01')->format('F Y') }}<br><strong>Status:</strong> {{ ucfirst($inv->status === 'zero' ? 'nothing to pay' : $inv->status) }}{{ $inv->paid_at ? ' on '.\Carbon\Carbon::parse($inv->paid_at)->toFormattedDateString() : '' }}</p>
<table>
  <tr><th>Seat</th><th class="amt">Amount</th></tr>
  @foreach ($lines as $l)
    <tr><td>{{ $l['description'] }}@if ($l['free_reason'])<div class="free">{{ $l['free_reason'] }}</div>@endif</td><td class="amt">${{ number_format($l['amount_cents'] / 100, 2) }}</td></tr>
  @endforeach
  <tr class="total"><td>Total</td><td class="amt">${{ number_format($inv->total_cents / 100, 2) }}</td></tr>
</table>
<p class="muted" style="margin-top:22px">Seats are billed for the days after their free trial while active. Any month in which no referral is offered to a seat is free.</p>
</body></html>
