<?php

declare(strict_types=1);

namespace App\Domains\Content\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Testimonials with recorded consent. Members write and agree in the portal (and can withdraw at any time); staff
 * approve them for the website. Only these appear as Reviews in structured data.
 */
final class TestimonialController
{
    public const CONSENT = 'I agree that Team to Team Referrals may publish this quote with my name and business on its website and in search results. I can withdraw this at any time.';

    public function mine(Request $request): Response
    {
        return Inertia::render('app/Testimonial', [
            'testimonials' => DB::table('testimonials')->where('user_id', $this->user($request)->id)->orderByDesc('id')->get(['id', 'quote', 'status', 'created_at'])->values(),
            'consent' => self::CONSENT, 'status' => session('status'), 'seo' => SeoData::private('Share your experience')->toArray(),
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        $d = $request->validate(['quote' => ['required', 'string', 'min:20', 'max:600'], 'consent' => ['accepted']], ['consent.accepted' => 'Please tick the box to agree we may publish it.']);
        $user = $this->user($request);
        $team = Team::of($user);
        DB::table('testimonials')->insert(['user_id' => $user->id, 'team_id' => $team?->id, 'quote' => trim(strip_tags((string) $d['quote'])), 'author_name' => $user->name,
            'business' => $team?->name, 'consent_text' => self::CONSENT, 'consented_at' => now(), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'testimonial-sent');
    }

    public function withdraw(Request $request, int $testimonial): RedirectResponse
    {
        DB::table('testimonials')->where(['id' => $testimonial, 'user_id' => $this->user($request)->id])->update(['status' => 'withdrawn', 'withdrawn_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'testimonial-withdrawn');
    }

    public function review(): Response
    {
        return Inertia::render('admin/content/Testimonials', [
            'testimonials' => DB::table('testimonials')->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")->orderByDesc('id')
                ->get(['id', 'quote', 'author_name', 'business', 'status', 'consented_at'])->values(),
            'status' => session('status'), 'seo' => SeoData::private('Testimonials')->toArray(),
        ]);
    }

    public function decide(Request $request, int $testimonial, string $decision): RedirectResponse
    {
        DB::table('testimonials')->where('id', $testimonial)->whereIn('status', ['pending', 'approved'])
            ->update(['status' => $decision === 'approve' ? 'approved' : 'declined', 'reviewed_by' => $request->user()?->id, 'updated_at' => now()]);

        return back()->with('status', 'testimonial-'.$decision);
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
