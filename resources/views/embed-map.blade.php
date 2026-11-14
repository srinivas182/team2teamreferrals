{{-- The embeddable network map: public facts only, no cookies. The page's own data is inline; it refreshes itself. --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $data['title'] }} network map · {{ $site }}</title>
@vite(['resources/css/app.css', 'resources/js/embed-map.tsx'])
</head>
<body class="bg-transparent text-ink">
<div id="t2t-map" data-src="{{ $src }}" data-page="{{ $page }}" data-base="{{ rtrim(url('/'), '/') }}">
  <p class="p-3 text-sm"><a href="{{ $page }}" target="_blank" rel="noopener">{{ $data['title'] }}: {{ $data['counts']['held'] }} places filled, {{ $data['counts']['open'] + $data['counts']['waitlist'] }} open, on {{ $site }}</a></p>
</div>
<script type="application/json" id="t2t-map-data" nonce="{{ Vite::cspNonce() }}">@json($data)</script>
</body>
</html>
