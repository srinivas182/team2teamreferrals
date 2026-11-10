<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Referral fee statement</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #0E1B3D; }
  h1 { font-size: 20px; margin: 0 0 4px; } .muted { color: #5B6785; } table { width: 100%; border-collapse: collapse; margin-top: 18px; }
  td { padding: 8px; border-top: 1px solid #E6EAF4; } .total td { font-weight: bold; font-size: 14px; border-top: 2px solid #0E1B3D; }
</style></head><body>
<h1>Referral fee statement</h1>
<div class="muted">{{ $ref->group->name }} · Reference {{ $ref->ulid }} · Issued {{ now()->toFormattedDateString() }}</div>
<table>
  <tr><td>Payable by (receiving team)</td><td>{{ $ref->receiver?->name }}</td></tr>
  <tr><td>Payable to (referring team)</td><td>{{ $ref->sender->name }}</td></tr>
  <tr><td>Client</td><td>{{ $ref->client_name }} · {{ $ref->seat->territory_name }}</td></tr>
  <tr><td>Closing date and value</td><td>{{ $ref->closing_date?->toFormattedDateString() }} · {{ $fee->basis_cents !== null ? '$'.number_format($fee->basis_cents / 100, 2) : '—' }}</td></tr>
  <tr><td>Fee terms (signed agreement)</td><td>{{ $terms }}</td></tr>
  <tr class="total"><td>Fee due</td><td>${{ number_format($fee->amount_cents / 100, 2) }} by {{ \Carbon\Carbon::parse($fee->due_on)->toFormattedDateString() }}</td></tr>
</table>
<p class="muted" style="margin-top:24px">Paid directly between the two businesses under their signed referral agreement. Team to Team Referrals does not collect or hold referral fees.</p>
</body></html>
