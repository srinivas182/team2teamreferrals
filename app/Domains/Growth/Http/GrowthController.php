<?php

declare(strict_types=1);

namespace App\Domains\Growth\Http;

use App\Domains\Content\Tokens;
use App\Domains\Growth\GrowthMailer;
use App\Domains\Growth\Prospect;
use App\Domains\Growth\Prospects;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Growth settings (prospect email), and the public unsubscribe page every prospect email links to. */
final class GrowthController
{
    public function __construct(private readonly Settings $settings) {}

    public function settings(): Response
    {
        return Inertia::render('growth/Settings', [
            'sending' => GrowthMailer::sendingOn(),
            'fromAddress' => (string) $this->settings->get('growth.from_address', 'hello@news.teamtoteamreferrals.com'),
            'fromName' => (string) $this->settings->get('growth.from_name', 'Team to Team Referrals'),
            'postalAddress' => Tokens::company()['postal_address'],
            'status' => session('status'),
            'seo' => SeoData::private('Growth settings')->toArray(),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $d = $request->validate(['sending' => ['required', 'boolean'], 'from_address' => ['required', 'email', 'max:190'], 'from_name' => ['required', 'string', 'max:80']]);
        if ($d['sending'] && trim(Tokens::company()['postal_address']) === '') {
            return back()->withErrors(['sending' => 'Add the company’s postal address in Admin → System → General first: every prospect email must show it.']);
        }
        $uid = $request->user()?->getAuthIdentifier();
        $this->settings->set('growth.sending', $d['sending'] ? '1' : '0', false, is_int($uid) ? $uid : null);
        $this->settings->set('growth.from_address', mb_strtolower((string) $d['from_address']), false, is_int($uid) ? $uid : null);
        $this->settings->set('growth.from_name', (string) $d['from_name'], false, is_int($uid) ? $uid : null);
        activity('admin')->causedBy($request->user())->event('growth-settings')->withProperties(['sending' => (bool) $d['sending'], 'from' => $d['from_address']])->log('Growth email settings saved');

        return back()->with('status', 'growth-settings-saved');
    }

    /** The unsubscribe page (the link is signed, so it can't be guessed for someone else). */
    public function unsubscribePage(string $prospect): Response
    {
        $p = Prospect::query()->where('ulid', $prospect)->first();

        return Inertia::render('GrowthUnsubscribe', ['done' => $p === null || $p->unsubscribed_at !== null, 'action' => request()->fullUrl(), 'seo' => SeoData::private('Unsubscribe')->toArray()]);
    }

    /** One click: from the page, or straight from the mail app (RFC 8058, no sign-in or form token). */
    public function unsubscribe(string $prospect): Response
    {
        $p = Prospect::query()->where('ulid', $prospect)->first();
        if ($p !== null && $p->unsubscribed_at === null) {
            $p->forceFill(['unsubscribed_at' => now()])->save();
            Prospects::suppress($p->email, 'unsubscribed');
            Prospects::log($p, null, 'email', 'Unsubscribed from prospect email.');
        }

        return Inertia::render('GrowthUnsubscribe', ['done' => true, 'action' => null, 'seo' => SeoData::private('Unsubscribe')->toArray()]);
    }
}
