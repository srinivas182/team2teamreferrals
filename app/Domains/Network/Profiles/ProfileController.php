<?php

declare(strict_types=1);

namespace App\Domains\Network\Profiles;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Performance\ServiceScore;
use App\Domains\Referrals\ProfileStats;
use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Public "refer to me" profile pages, their QR codes, and referrals sent from them. */
final class ProfileController
{
    public function show(Request $request, string $slug): Response
    {
        $team = $this->publicTeam($slug);
        // Counted once per visitor per day; QR scans and shared-link clicks separately.
        $key = "profile_seen.{$team->id}.".now()->toDateString();
        if (! $request->session()->has($key)) {
            $request->session()->put($key, true);
            PublicProfiles::count($team, 'view');
            if (in_array($request->query('src'), ['qr', 'link'], true)) {
                PublicProfiles::count($team, (string) $request->query('src'));
            }
        }
        $seats = Seat::query()->with('group')->where('primary_team_id', $team->id)->get();
        $record = ProfileStats::for($team);
        $score = ServiceScore::for($team)['score'];
        $cities = $seats->map(fn (Seat $s) => $s->territory_name)->unique()->values();
        $groups = $seats->map(fn (Seat $s) => $s->group->name)->unique()->values();
        $user = $request->user();
        $viewerTeam = $user instanceof User ? Team::of($user) : null;
        $path = '/t/'.$team->slug;
        $desc = "{$team->name}: verified ".$groups->implode(', ').' member serving '.$cities->implode(', ').'. Refer a client and track every step on Team to Team Referrals.';

        return Inertia::render('TeamPublic', [
            'team' => ['name' => $team->name, 'slug' => $team->slug, 'bio' => $team->bio, 'website' => $team->website, 'phone' => $team->phone, 'serviceArea' => $team->service_area,
                'foundedYear' => $team->founded_year, 'specialties' => json_decode((string) DB::table('teams')->where('id', $team->id)->value('specialties'), true) ?: [], 'languages' => json_decode((string) DB::table('teams')->where('id', $team->id)->value('languages'), true) ?: [], 'logo' => $team->logo_path ? Storage::disk('public')->url($team->logo_path) : null],
            'cities' => $cities, 'groups' => $groups,
            'licenses' => DB::table('team_licenses')->join('states', 'states.id', '=', 'team_licenses.state_id')->where(['team_id' => $team->id, 'status' => 'verified'])->pluck('states.code')->unique()->values(),
            'serviceScore' => $score, 'record' => $record,
            'viewer' => $viewerTeam !== null ? ($viewerTeam->id === $team->id ? 'self' : 'member') : 'guest',
            'sendUrl' => $seats->isNotEmpty() ? '/app/send?group='.$seats->first()->group->slug.'&city='.$seats->first()->territory_id.'&from='.$team->slug : null,
            'started' => encrypt(now()->timestamp),
            'status' => session('status'),
            'seo' => (new SeoData(title: "{$team->name} · ".$groups->first().' in '.$cities->first(), description: $desc, path: $path,
                schema: [['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $team->name, 'url' => url($path), 'description' => $team->bio ?: $desc,
                    'areaServed' => $cities->all(), 'telephone' => $team->phone, 'sameAs' => $team->website ? [$team->website] : []]
                    + ($record['rating'] !== null ? ['aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => $record['rating'], 'ratingCount' => $record['ratings']]] : []),
                    Schema::breadcrumbs([['Home', '/'], [$team->name, $path]])]))->toArray(),
        ]);
    }

    public function qr(string $slug): \Illuminate\Http\Response
    {
        return response(PublicProfiles::qrSvg($this->publicTeam($slug)), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400']);
    }

    /** A non-member refers a client: checked for spam, then confirmed by email before the team sees it. */
    public function refer(Request $request, string $slug): RedirectResponse
    {
        $team = $this->publicTeam($slug);
        $d = $request->validate([
            'referrer_name' => ['required', 'string', 'max:120'], 'referrer_email' => ['required', 'email:rfc', 'max:190'], 'referrer_business' => ['nullable', 'string', 'max:160'],
            'client_name' => ['required', 'string', 'max:120'], 'client_phone' => ['nullable', 'required_without:client_email', 'string', 'max:32'], 'client_email' => ['nullable', 'email:rfc', 'max:190'],
            'need' => ['required', 'string', 'min:10', 'max:2000'], 'consent' => ['accepted'], 'started' => ['required', 'string'],
        ], ['consent.accepted' => 'Please confirm the client agreed to be contacted.']);
        // Bots fill the hidden field or post instantly; a form left open for hours has gone stale.
        $started = rescue(fn () => decrypt((string) $d['started']), 0, false);
        $elapsed = (int) now()->timestamp - (is_numeric($started) ? (int) $started : 0);
        if (filled($request->input('website_url')) || $elapsed < 3 || $elapsed > 7200) {
            throw ValidationException::withMessages(['need' => 'Please try sending that again.']);
        }
        $phone = $d['client_phone'] ? preg_replace('/\D+/', '', (string) $d['client_phone']) : null;
        $duplicate = DB::table('profile_leads')->where('team_id', $team->id)->where('created_at', '>=', now()->subDays(30))
            ->where(fn ($q) => $q->when($phone, fn ($w) => $w->orWhere('client_phone', $phone))->when($d['client_email'] ?? null, fn ($w) => $w->orWhere('client_email', strtolower((string) $d['client_email']))))->exists();
        if ($duplicate) {
            return back()->with('status', 'profile-lead-duplicate');
        }
        $token = Str::random(40);
        DB::table('profile_leads')->insert([
            'team_id' => $team->id, 'referrer_name' => $d['referrer_name'], 'referrer_email' => strtolower((string) $d['referrer_email']), 'referrer_business' => $d['referrer_business'] ?? null,
            'client_name' => $d['client_name'], 'client_phone' => $phone, 'client_email' => isset($d['client_email']) ? strtolower((string) $d['client_email']) : null, 'need' => $d['need'],
            'consent_text' => 'The referrer confirmed the client agreed to be contacted by '.$team->name.'.', 'verify_token_hash' => hash('sha256', $token),
            'ip_hash' => hash('sha256', (string) $request->ip().'|'.config('app.key')), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Notification::route('mail', (string) $d['referrer_email'])->notify(new ProfileLeadVerify($team->name, url('/t/'.$team->slug.'/confirm/'.$token)));

        return back()->with('status', 'profile-lead-check-email');
    }

    public function confirm(string $slug, string $token, Notifier $notifier): RedirectResponse
    {
        $team = $this->publicTeam($slug);
        $lead = DB::table('profile_leads')->where(['team_id' => $team->id, 'verify_token_hash' => hash('sha256', $token)])->first();
        abort_if($lead === null, 404);
        DB::table('profile_leads')->where('id', $lead->id)->update(['status' => 'new', 'verified_at' => now(), 'verify_token_hash' => null, 'updated_at' => now()]);
        PublicProfiles::count($team, 'referral');
        // Invite the referrer through this team's link (the team earns the invite reward if they join and qualify).
        Notification::route('mail', (string) $lead->referrer_email)->notify(new ProfileLeadInvite($team->name, url('/join/'.$team->invite_code)));
        $notifier->send($team->leader, 'profile.lead', ['headline' => "New lead from your profile: {$lead->client_name}", 'body' => "{$lead->referrer_name} sent it through your public profile. Contact them soon."], url('/app/profile-leads'));

        return redirect('/t/'.$team->slug)->with('status', 'profile-lead-confirmed');
    }

    private function publicTeam(string $slug): Team
    {
        $team = Team::query()->with('leader')->where('slug', $slug)->where('profile_public', true)->first();
        // Only teams holding a seat have a public profile.
        abort_if($team === null || ! Seat::query()->where('primary_team_id', $team->id)->exists(), 404);

        return $team;
    }
}
