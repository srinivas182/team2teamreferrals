<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Referral agreement</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0E1B3D; line-height: 1.5; }
  h1 { font-size: 18px; margin: 0 0 4px; } h2 { font-size: 12px; margin: 14px 0 4px; } .muted { color: #5B6785; }
  .cert { margin-top: 24px; border: 1px solid #C9D2EA; border-radius: 8px; padding: 12px; page-break-inside: avoid; }
  table { width: 100%; border-collapse: collapse; } td { padding: 4px 6px; border-top: 1px solid #E6EAF4; vertical-align: top; }
</style></head><body>
<h1>Referral agreement</h1>
<div class="muted">{{ $ref->group->name }} · {{ $ref->seat->territory_name }} · Reference {{ $ref->ulid }} · {{ ucfirst((string) $a->scope) }} version {{ $a->version }}</div>
@foreach ($sections as $s)
  <h2>{{ $s['title'] }}</h2><div>{{ $s['text'] }}</div>
@endforeach
<div class="cert">
  <strong>Signature certificate</strong>
  <table>
    @foreach (['sender' => 'Referring team', 'receiver' => 'Receiving team'] as $side => $label)
      <tr><td>{{ $label }}</td><td>{{ $a->{$side.'_signed_name'} }}</td><td>{{ $a->{$side.'_signed_at'} }} UTC</td><td>IP {{ $a->{$side.'_ip'} }}</td></tr>
      <tr><td></td><td colspan="3" class="muted">{{ $a->{$side.'_device'} }}</td></tr>
    @endforeach
  </table>
  <div class="muted">Each signer agreed to sign electronically and typed their full name. Document fingerprint (SHA-256): {{ $a->content_hash }}</div>
</div>
</body></html>
