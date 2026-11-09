<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#08112A" media="(prefers-color-scheme: dark)">
    {{-- Applies the saved theme before first paint, so dark mode never flashes. --}}
    <script nonce="{{ Vite::cspNonce() }}">try{var t=localStorage.getItem('t2t-theme');if(t==='light'||t==='dark'){document.documentElement.dataset.theme=t}}catch(e){}</script>
    <link rel="icon" href="/brand/favicon.ico" sizes="48x48">
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/brand/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    @fonts
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>
