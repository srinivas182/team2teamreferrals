{{-- A widget's page, shown in a frame on a member's own website. Plain HTML and inline styles, no cookies. --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $f ? $f['name'].' on '.$site : $site }}</title>
<style>
  :root { --accent: {{ $accent }}; --fg: {{ $theme === 'dark' ? '#F1F4FB' : '#0E1B3D' }}; --muted: {{ $theme === 'dark' ? '#A9B3CC' : '#5B6785' }};
          --bg: {{ $theme === 'dark' ? '#0E1B3D' : '#FFFFFF' }}; --line: {{ $theme === 'dark' ? '#2A3A66' : '#E3E8F2' }}; }
  * { box-sizing: border-box; margin: 0; }
  body { font: 14px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--fg); background: transparent; }
  .card { background: var(--bg); border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; }
  a { color: inherit; text-decoration: none; }
  .row { display: flex; gap: 10px; align-items: center; }
  .logo { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; flex: none; background: var(--line); display: grid; place-items: center; font-weight: 700; }
  .name { font-weight: 700; }
  .muted { color: var(--muted); font-size: 12px; }
  .tick { color: var(--accent); font-weight: 700; }
  .stars { color: #F5A524; letter-spacing: 1px; }
  .btn { display: block; text-align: center; background: var(--accent); color: #fff; font-weight: 700; padding: 12px 16px; border-radius: 10px; }
  .review { border-top: 1px solid var(--line); padding-top: 8px; margin-top: 8px; }
  .brand { margin-top: 8px; font-size: 11px; color: var(--muted); }
</style>
</head>
<body>
@if ($f)
  @php($go = fn ($w, $to = null) => url('/embed/'.$f['slug'].'/go/'.$w).'?'.http_build_query(array_filter(['to' => $to, 'from' => $from])))
  @php($stars = fn ($n) => str_repeat('★', (int) round($n)).str_repeat('☆', 5 - (int) round($n)))
  @if ($widget === 'button')
    <a class="btn" href="{{ $go('button', 'refer') }}" target="_blank" rel="noopener">Refer a client to {{ $f['name'] }}</a>
    <p class="brand">Verified on {{ $site }}</p>
  @elseif ($widget === 'reviews')
    <div class="card">
      <a class="row" href="{{ $go('reviews') }}" target="_blank" rel="noopener">
        <span class="name">{{ $f['name'] }}</span>
      </a>
      @if ($f['enoughRatings'])
        <p><span class="stars" aria-hidden="true">{{ $stars($f['rating']) }}</span> {{ $f['rating'] }} out of 5 from {{ $f['ratings'] }} ratings</p>
        @foreach ($f['reviews'] as $r)
          <div class="review">
            <span class="stars" aria-label="{{ $r['stars'] }} out of 5">{{ $stars($r['stars']) }}</span>
            <p>{{ \Illuminate\Support\Str::limit($r['comment'], 180) }}</p>
            <p class="muted">{{ $r['by'] === 'client' ? 'Verified client' : ($r['from'] ?? 'Referring team') }} · Verified referral · {{ $r['date'] }}</p>
          </div>
        @endforeach
      @else
        <p class="muted">{{ $f['ratings'] > 0 ? $f['ratings'].' ratings so far' : 'New on the network' }}: ratings show once there are three.</p>
      @endif
      <p class="brand"><a href="{{ $go('reviews') }}" target="_blank" rel="noopener">See all reviews on {{ $site }} →</a></p>
    </div>
  @else
    <a class="card row" href="{{ $go('badge') }}" target="_blank" rel="noopener">
      @if ($f['logo'])<img class="logo" src="{{ $f['logo'] }}" alt="">@else<span class="logo">{{ mb_substr($f['name'], 0, 1) }}</span>@endif
      <span>
        <span class="name">{{ $f['name'] }}</span><br>
        <span><span class="tick">✓</span> Verified {{ $f['pro'] }} · {{ $f['territory'] }} seat holder</span><br>
        <span class="muted">{{ $f['enoughRatings'] ? $f['rating'].'★ from '.$f['ratings'].' ratings · ' : '' }}{{ $f['licenses'] ? 'License verified ('.implode(', ', $f['licenses']).') · ' : '' }}{{ $site }}</span>
      </span>
    </a>
  @endif
@endif
<script nonce="{{ $nonce }}">
  (function () { function h() { parent.postMessage({ t2t: 1, h: {{ $f ? 'document.body.scrollHeight' : '0' }} }, '*'); } h(); addEventListener('load', h); addEventListener('resize', h); })();
</script>
</body>
</html>
