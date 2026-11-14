<?php

declare(strict_types=1);

namespace App\Domains\Network\Profiles\Widgets;

use App\Domains\Network\Seats\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;

/**
 * The embed endpoints. They run without sessions or cookies: the loader script, each widget's small page (shown in
 * a frame only on the team's own websites, enforced by the browser through frame-ancestors) and the click-through.
 */
final class EmbedController
{
    /** The loader members paste once: it turns each placeholder into its widget and sizes it. */
    public function script(): Response
    {
        $js = <<<'JS'
(function () {
  var s = document.currentScript, base = s ? s.src.replace(/\/embed\/v1\.js.*$/, '') : '';
  var sizes = { badge: [340, 96], reviews: [420, 320], button: [300, 64] };
  function mount(el) {
    if (el.getAttribute('data-t2t-mounted')) return;
    var team = el.getAttribute('data-team') || '', w = el.getAttribute('data-widget') || 'badge';
    if (!/^[a-z0-9-]+$/.test(team) || !sizes[w]) return;
    el.setAttribute('data-t2t-mounted', '1');
    var accent = (el.getAttribute('data-accent') || '').replace('#', '');
    var f = document.createElement('iframe');
    f.src = base + '/embed/' + team + '/' + w + '?theme=' + encodeURIComponent(el.getAttribute('data-theme') || 'light') + '&accent=' + encodeURIComponent(accent);
    f.title = el.getAttribute('data-title') || 'Team to Team Referrals';
    f.setAttribute('scrolling', 'no');
    // Starts collapsed beside the plain link; it only takes the link's place once it reports in, so a site the
    // team hasn't listed (where browsers refuse the frame) keeps showing the link instead of an empty box.
    f.style.cssText = 'border:0;display:block;width:100%;max-width:' + sizes[w][0] + 'px;height:0;overflow:hidden;background:transparent';
    el.appendChild(f);
  }
  window.addEventListener('message', function (e) {
    if (e.origin !== base || !e.data || e.data.t2t !== 1) return;
    var frames = document.querySelectorAll('.t2t-widget iframe');
    for (var i = 0; i < frames.length; i++) {
      if (frames[i].contentWindow !== e.source) continue;
      frames[i].style.height = Math.min(800, Math.max(0, e.data.h | 0)) + 'px';
      var el = frames[i].parentNode;
      for (var n = el.firstChild; n;) { var next = n.nextSibling; if (n !== frames[i]) el.removeChild(n); n = next; }
    }
  });
  var els = document.querySelectorAll('.t2t-widget');
  for (var i = 0; i < els.length; i++) mount(els[i]);
})();
JS;

        return response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=3600, s-maxage=86400']);
    }

    /** One widget, checked live: empty (and collapsed) when the team no longer qualifies. */
    public function frame(Request $request, string $slug, string $widget): Response
    {
        $team = Team::query()->where('slug', $slug)->first();
        $facts = $team !== null ? Widgets::facts($team) : null;
        $theme = $request->query('theme') === 'dark' ? 'dark' : 'light';
        $accent = preg_match('/^[0-9a-fA-F]{6}$/', (string) $request->query('accent')) ? '#'.$request->query('accent') : '#2451F5';
        // Counted only on the team's own websites (the preview on our site and stray copies aren't views).
        $from = $team !== null ? Widgets::allowedReferer($team, $request->headers->get('referer')) : null;
        if ($team !== null && $facts !== null && $from !== null) {
            Widgets::count($team, $widget, 'view', $from);
        }
        $nonce = Vite::cspNonce();
        $csp = implode('; ', ["default-src 'none'", "style-src 'unsafe-inline'", "img-src 'self' data: https:", "script-src 'nonce-{$nonce}'", "base-uri 'none'", "form-action 'none'",
            $team !== null ? Widgets::frameAncestors($team) : "frame-ancestors 'none'"]);

        return response()->view('embed', ['f' => $facts, 'widget' => $widget, 'theme' => $theme, 'accent' => $accent, 'nonce' => $nonce, 'site' => (string) config('seo.site_name'), 'from' => $from], 200, [
            'Content-Security-Policy' => $csp, 'Cache-Control' => 'private, max-age=60', 'X-Robots-Tag' => 'noindex',
        ]);
    }

    /** Click-through from a widget: counted, then on to the team's profile (or its referral form). */
    public function go(Request $request, string $slug, string $widget): RedirectResponse
    {
        $team = Team::query()->where('slug', $slug)->where('profile_public', true)->firstOrFail();
        // The click comes from inside the frame, so the frame passes on which website it was shown on.
        $from = Widgets::allowedReferer($team, (string) $request->query('from'));
        if ($from !== null) {
            Widgets::count($team, $widget, 'click', $from);
        }

        return redirect('/t/'.$team->slug.'?via='.$widget.($request->query('to') === 'refer' ? '#refer' : ''));
    }
}
