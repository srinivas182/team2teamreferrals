<?php

declare(strict_types=1);

namespace App\Domains\Network\Profiles;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** The team's side of its public profile: share link, QR, counts, email signature, printable card, and leads. */
final class ShareController
{
    public function show(Request $request): Response
    {
        $team = $this->team($request);
        $link = PublicProfiles::url($team, 'link');

        return Inertia::render('app/ShareProfile', [
            'link' => $link, 'profile' => PublicProfiles::url($team), 'qr' => '/t/'.$team->slug.'/qr.svg', 'public' => (bool) $team->profile_public,
            'stats' => PublicProfiles::last30($team),
            // A ready-made signature: plain HTML with inline styles, as email programs need.
            'signature' => '<table style="font-family:Arial,sans-serif;font-size:13px;color:#0E1B3D"><tr><td><strong>'.e($team->name).'</strong>'.($team->phone ? '<br>'.e($team->phone) : '')
                .'<br><a href="'.e($link).'" style="display:inline-block;margin-top:6px;padding:6px 12px;background:#2451F5;color:#fff;border-radius:6px;text-decoration:none">Refer a client to us</a></td></tr></table>',
            'canEdit' => in_array($team->roleOf($this->user($request)), ['leader', 'assistant'], true),
            'status' => session('status'),
            'seo' => SeoData::private('Share your profile')->toArray(),
        ]);
    }

    public function toggle(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        abort_unless(in_array($team->roleOf($this->user($request)), ['leader', 'assistant'], true), 403);
        $team->forceFill(['profile_public' => $request->boolean('public')])->save();

        return back()->with('status', $team->profile_public ? 'profile-shown' : 'profile-hidden');
    }

    /** A printable card: the QR code, the team name and the profile address. */
    public function card(Request $request): \Illuminate\Http\Response
    {
        $team = $this->team($request);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.qr-card', ['team' => $team, 'qr' => base64_encode(PublicProfiles::qrSvg($team, 600)), 'url' => PublicProfiles::url($team)])
            ->setPaper('a6')->download('refer-to-'.$team->slug.'.pdf');
    }

    public function leads(Request $request): Response
    {
        $team = $this->team($request);

        return Inertia::render('app/ProfileLeads', [
            'leads' => DB::table('profile_leads')->where('team_id', $team->id)->where('status', '!=', 'unverified')->orderByDesc('id')->limit(200)->get()
                ->map(fn ($l) => ['id' => (int) $l->id, 'status' => (string) $l->status, 'at' => substr((string) $l->verified_at, 0, 10), 'client' => (string) $l->client_name,
                    'phone' => $l->client_phone, 'email' => $l->client_email, 'need' => (string) $l->need, 'referrer' => $l->referrer_name.($l->referrer_business ? ' · '.$l->referrer_business : ''), 'referrerEmail' => (string) $l->referrer_email]),
            'seo' => SeoData::private('Profile leads')->toArray(),
        ]);
    }

    public function leadStatus(Request $request, int $lead): RedirectResponse
    {
        $team = $this->team($request);
        DB::table('profile_leads')->where(['id' => $lead, 'team_id' => $team->id])->where('status', '!=', 'unverified')
            ->update(['status' => (string) $request->validate(['status' => ['required', 'in:new,contacted,closed']])['status'], 'updated_at' => now()]);

        return back();
    }

    private function team(Request $request): Team
    {
        $u = $this->user($request);
        $team = Team::of($u) ?? Team::forLeader($u);
        PublicProfiles::ensureSlug($team);

        return $team;
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
