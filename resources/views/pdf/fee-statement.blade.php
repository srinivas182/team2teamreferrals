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
  <tr><td>Receiving team (pays the referral fee)</td><td>{{ $ref->receiver?->name }}</td></tr>
  <tr><td>Referring team (earns the referral fee)</td><td>{{ $ref->sender->name }}</td></tr>
  <tr><td>Client</td><td>{{ $ref->client_name }} · {{ $ref->seat->territory_name }} · closed {{ $ref->closing_date?->toFormattedDateString() }}</td></tr>
  @if ($fee->basis_cents !== null)
  <tr><td>Receiving team’s earnings from this referral</td><td>${{ number_format($fee->basis_cents / 100, 2) }}</td></tr>
  @endif
  <tr><td>Fee terms (signed agreement)</td><td>{{ $terms }}</td></tr>
  <tr class="total"><td>Referral fee due to the referring team</td><td>${{ number_format($fee->amount_cents / 100, 2) }} by {{ \Carbon\Carbon::parse($fee->due_on)->toFormattedDateString() }}</td></tr>
  @if ($fee->platform_fee_cents > 0)
  <tr><td>Platform fee (paid by the referring team from this referral fee, once received)</td><td>${{ number_format($fee->platform_fee_cents / 100, 2) }}</td></tr>
  <tr><td>Referring team’s net earnings</td><td>${{ number_format(($fee->amount_cents - $fee->platform_fee_cents) / 100, 2) }}</td></tr>
  @endif
</table>
<p class="muted" style="margin-top:24px">The referral fee is paid directly between the two businesses under their signed referral agreement. Team to Team Referrals never collects or holds it; the platform fee is billed to the referring team’s account.</p>
</body></html>
