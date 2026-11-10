<?php

declare(strict_types=1);

namespace App\Domains\Network\Prelaunch;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Seo\SeoData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ReserveController
{
    public function __construct(private readonly Reservations $reservations) {}

    public function page(): Response
    {
        return Inertia::render('Reserve', [
            'launchAt' => $this->reservations->launchAt(),
            'counters' => $this->reservations->counters(),
            'wall' => $this->reservations->wall(),
            'points' => $this->reservations->points(),
            'race' => $this->reservations->race(),
            'industries' => Industry::query()->orderBy('name')->get(['slug', 'name', 'territory_unit'])->map(fn (Industry $i) => ['slug' => $i->slug, 'name' => $i->name, 'territory' => $i->territory_unit]),
            'status' => session('status'),
            'seo' => (new SeoData(title: 'Reserve your city before launch', description: 'One verified pro per city, per industry. Founding members keep their price for as long as they hold the seat.'))->toArray(),
        ]);
    }

    public function check(Request $request): JsonResponse
    {
        $d = $request->validate(['industry' => ['required', 'exists:industries,slug'], 'city_id' => ['required', 'integer', 'exists:cities,id']]);

        return response()->json($this->reservations->availability(Industry::query()->where('slug', $d['industry'])->firstOrFail(), City::query()->findOrFail((int) $d['city_id'])));
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'industry' => ['required', 'exists:industries,slug'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'name' => ['required', 'string', 'max:160'],
            'business' => ['nullable', 'string', 'max:160'],
            'email' => ['required', 'email'],
            'on_wall' => ['boolean'],
            'website' => ['prohibited'], // honeypot
            'ref' => ['nullable', 'string', 'size:26'],
        ]);
        $this->reservations->reserve(Industry::query()->where('slug', $d['industry'])->firstOrFail(), City::query()->findOrFail((int) $d['city_id']), (string) $d['email'], (string) $d['name'], $d['business'] ?? null, (bool) ($d['on_wall'] ?? true), $d['ref'] ?? null);

        return back()->with('status', 'reservation-sent');
    }

    public function confirm(Request $request, string $token): Response
    {
        $r = $this->reservations->confirm($token);
        abort_if($r === null, 404);
        if ($request->user() === null) {
            $request->session()->put('url.intended', url('/reserve/claim/'.$token));
        }

        return Inertia::render('ReserveConfirmed', ['token' => $token, 'email' => (string) $r->email, 'status' => (string) $r->status, 'signedIn' => $request->user() !== null,
            'share' => $r->share_id ? $this->reservations->shared((string) $r->share_id) : null,
            'seo' => (new SeoData(title: 'Reservation confirmed', description: 'Team to Team Referrals', index: false))->toArray()]);
    }

    /** Public share page: the link members post. It previews nicely and doubles as their invite link. */
    public function share(string $shareId): Response
    {
        $s = $this->reservations->shared($shareId);
        abort_if($s === null, 404);

        return Inertia::render('ReserveShare', [
            'share' => $s,
            'seo' => (new SeoData(title: "{$s['business']} reserved {$s['territory']}", description: "Founding member of {$s['group']} on Team to Team Referrals. Reserve your city before launch: one verified pro per city, per industry.",
                path: "/reserve/c/{$shareId}", image: "/reserve/c/{$shareId}/og.png"))->toArray(),
            'caption' => "We’ve reserved {$s['territory']} on Team to Team Referrals as a founding member of {$s['group']}. Have a client moving here? We’re the verified pro to call.",
        ]);
    }

    /** The card image: stored once on the CDN disk, rendered on demand if missing, brand image as a last resort. */
    public function image(string $shareId, string $size): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless(isset(ShareImage::SIZES[$size]), 404);
        $path = ShareImage::path($shareId, $size);
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        if (! $disk->exists($path)) {
            $s = $this->reservations->shared($shareId);
            abort_if($s === null, 404);
            if (! ShareImage::generate($shareId, $s['business'], $s['territory'], $s['group'])) {
                return redirect('/brand/og-image.png');
            }
        }

        return response((string) $disk->get($path), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function claim(Request $request, string $token): RedirectResponse
    {
        /** @var User $u */
        $u = $request->user();
        $this->reservations->claim($token, $u);

        return redirect()->to('/app')->with('status', 'reservation-claimed');
    }
}
