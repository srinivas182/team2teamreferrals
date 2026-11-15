<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http;

use App\Domains\Integrations\Connectors;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Integrations: switch each CRM on or off, the platform's Follow Up Boss registration, and health. */
final class AdminIntegrationsController
{
    public function __construct(private readonly Settings $settings) {}

    public function index(): Response
    {
        $since = now()->subDay();
        $logs = DB::table('sync_logs as l')->join('integrations as i', 'i.id', '=', 'l.integration_id')->where('l.created_at', '>=', $since)
            ->selectRaw('i.provider, l.status, count(*) as n')->groupBy('i.provider', 'l.status')->get();

        return Inertia::render('admin/Integrations', [
            'providers' => collect(Connectors::all())->map(fn ($p, $key) => [
                'key' => $key, 'label' => $p->label(), 'enabled' => $this->settings->get("integrations.{$key}.enabled", '1') === '1', 'ready' => $p->ready(),
                'connections' => DB::table('integrations')->where('provider', $key)->count(), 'needsAttention' => DB::table('integrations')->where(['provider' => $key, 'status' => 'error'])->count(),
                'ok' => (int) $logs->where('provider', $key)->where('status', 'ok')->sum('n'), 'failed' => (int) $logs->where('provider', $key)->where('status', 'failed')->sum('n'),
            ])->values(),
            'fub' => ['system' => $this->settings->get('integrations.followupboss.system'), 'key' => $this->settings->masked('integrations.followupboss.system_key')],
            'calendars' => collect(['google' => 'Google Calendar', 'microsoft' => 'Outlook (Microsoft 365)'])->map(fn ($label, $key) => ['key' => $key, 'label' => $label,
                'enabled' => $this->settings->get("integrations.{$key}.enabled", '1') === '1', 'clientId' => $this->settings->get("integrations.{$key}.client_id"),
                'secret' => $this->settings->masked("integrations.{$key}.client_secret"), 'redirect' => \App\Domains\Integrations\Calendar\CalendarProvider::redirectUri($key),
                'connections' => DB::table('calendar_connections')->where('provider', $key)->count()])->values(),
            'zapierInvite' => $this->settings->get('integrations.zapier.invite_url'),
            'status' => session('status'),
            'seo' => SeoData::private('Integrations')->toArray(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $d = $request->validate(['enabled' => ['sometimes', 'array'], 'enabled.*' => ['boolean'], 'fub_system' => ['sometimes', 'nullable', 'string', 'max:100'], 'fub_system_key' => ['sometimes', 'nullable', 'string', 'max:200'],
            'calendar' => ['sometimes', 'array'], 'calendar.*.enabled' => ['boolean'], 'calendar.*.client_id' => ['nullable', 'string', 'max:255'], 'calendar.*.client_secret' => ['nullable', 'string', 'max:255'],
            'zapier_invite' => ['sometimes', 'nullable', 'url:https', 'max:500', 'starts_with:https://zapier.com/']], ['zapier_invite.starts_with' => 'Paste the invite link from Zapier (it starts with https://zapier.com/).']);
        $uid = $request->user()?->getAuthIdentifier();
        foreach ((array) ($d['enabled'] ?? []) as $key => $on) {
            if (isset(Connectors::PROVIDERS[$key])) {
                $this->settings->set("integrations.{$key}.enabled", $on ? '1' : '0', false, is_int($uid) ? $uid : null);
            }
        }
        if (array_key_exists('fub_system', $d)) {
            $this->settings->set('integrations.followupboss.system', $d['fub_system'] !== null ? trim((string) $d['fub_system']) : null, false, is_int($uid) ? $uid : null);
        }
        // A blank key field keeps the saved key.
        if (! empty($d['fub_system_key'])) {
            $this->settings->set('integrations.followupboss.system_key', trim((string) $d['fub_system_key']), true, is_int($uid) ? $uid : null);
        }
        foreach ((array) ($d['calendar'] ?? []) as $key => $c) {
            if (! in_array($key, ['google', 'microsoft'], true)) {
                continue;
            }
            $this->settings->set("integrations.{$key}.enabled", ! empty($c['enabled']) ? '1' : '0', false, is_int($uid) ? $uid : null);
            if (array_key_exists('client_id', $c)) {
                $this->settings->set("integrations.{$key}.client_id", $c['client_id'] !== null ? trim((string) $c['client_id']) : null, false, is_int($uid) ? $uid : null);
            }
            if (! empty($c['client_secret'])) {
                $this->settings->set("integrations.{$key}.client_secret", trim((string) $c['client_secret']), true, is_int($uid) ? $uid : null);
            }
        }
        if (array_key_exists('zapier_invite', $d)) {
            $this->settings->set('integrations.zapier.invite_url', $d['zapier_invite'], false, is_int($uid) ? $uid : null);
        }
        activity('admin')->causedBy($request->user())->event('integrations-settings')->withProperties(['enabled' => $d['enabled'] ?? null, 'fub_system' => array_key_exists('fub_system', $d), 'fub_key' => ! empty($d['fub_system_key']), 'calendars' => array_keys((array) ($d['calendar'] ?? []))])->log('Integration settings saved');

        return back()->with('status', 'integrations-saved');
    }
}
