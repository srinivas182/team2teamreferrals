<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Referral earnings {{ $year }}</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0E1B3D; } h1 { font-size: 20px; margin: 0 0 4px; } .muted { color: #5B6785; }
  table { width: 100%; border-collapse: collapse; margin-top: 14px; } th { text-align: left; color: #5B6785; font-weight: normal; padding: 5px; } td { padding: 5px; border-top: 1px solid #E6EAF4; }
  .r { text-align: right; } .total td { font-weight: bold; border-top: 2px solid #0E1B3D; }
</style></head><body>
@php
  $in = $rows->where('payee_team_id', $team->id)->where('status', 'received');
  $out = $rows->where('payer_team_id', $team->id)->whereIn('status', ['paid', 'received']);
  $gross = $in->sum('amount_cents'); $platform = $in->sum('platform_fee_cents'); $paid = $out->sum('amount_cents');
@endphp
<h1>Referral earnings {{ $year }}</h1>
<div class="muted">{{ $team->name }} · Team to Team Referrals · prepared {{ now()->toFormattedDateString() }}</div>
<table>
  <tr><td>Referral fees received (gross)</td><td class="r">${{ number_format($gross / 100, 2) }}</td></tr>
  <tr><td>Platform fees on those referral fees</td><td class="r">−${{ number_format($platform / 100, 2) }}</td></tr>
  <tr class="total"><td>Net referral earnings</td><td class="r">${{ number_format(($gross - $platform) / 100, 2) }}</td></tr>
  <tr><td>Referral fees paid to other teams</td><td class="r">${{ number_format($paid / 100, 2) }}</td></tr>
</table>
<table>
  <tr><th>Date</th><th>Client</th><th>With</th><th>Direction</th><th>Status</th><th class="r">Fee</th><th class="r">Platform fee</th></tr>
  @foreach ($rows as $r)
    @php $incoming = (int) $r->payee_team_id === $team->id; @endphp
    <tr><td>{{ substr((string) $r->created_at, 0, 10) }}</td><td>{{ $r->client_name }}</td><td>{{ $incoming ? $r->payer : $r->payee }}</td><td>{{ $incoming ? 'Received' : 'Paid' }}</td><td>{{ $r->status }}</td>
      <td class="r">${{ number_format($r->amount_cents / 100, 2) }}</td><td class="r">{{ $incoming ? '$'.number_format($r->platform_fee_cents / 100, 2) : '' }}</td></tr>
  @endforeach
</table>
<p class="muted" style="margin-top:18px">Referral fees are paid directly between businesses under their signed agreements. Please confirm figures with your accountant.</p>
</body></html>
