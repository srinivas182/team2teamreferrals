<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Models\PushSubscription;
use App\Domains\Messaging\NotificationCatalogue;
use App\Domains\Messaging\Preferences;
use App\Domains\Messaging\Push\WebPushSender;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

final class PreferencesController
{
    public function __construct(private readonly Preferences $prefs) {}

    public function show(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('app/settings/Notifications', [
            'types' => collect(NotificationCatalogue::TYPES)->map(fn ($d, $key) => [
                'key' => $key, 'group' => $d['group'], 'label' => $d['label'], 'description' => $d['description'],
                'channels' => $d['channels'], 'urgent' => $d['urgent'], 'locked' => $d['locked'],
            ])->values(),
            'channels' => NotificationCatalogue::CHANNELS,
            'matrix' => $this->prefs->matrix($user),
            'quiet' => ['start' => $user->quiet_hours_start ? substr($user->quiet_hours_start, 0, 5) : null, 'end' => $user->quiet_hours_end ? substr($user->quiet_hours_end, 0, 5) : null, 'timezone' => $user->timezone],
            'digest' => $user->email_digest,
            'pushDevices' => PushSubscription::query()->where('user_id', $user->id)->count(),
            'vapidPublicKey' => fn () => app(WebPushSender::class)->keys()['publicKey'],
            'status' => session('status'),
            'seo' => SeoData::private('Notification settings')->toArray(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'matrix' => ['array'],
            'matrix.*' => ['array'],
            'matrix.*.*' => ['boolean'],
            'quiet_start' => ['nullable', 'date_format:H:i', 'required_with:quiet_end'],
            'quiet_end' => ['nullable', 'date_format:H:i', 'required_with:quiet_start', 'different:quiet_start'],
            'digest' => ['boolean'],
        ], ['quiet_end.different' => 'Quiet hours need to start and end at different times.']);

        foreach ((array) ($data['matrix'] ?? []) as $type => $channels) {
            foreach ((array) $channels as $channel => $on) {
                $this->prefs->set($user, (string) $type, (string) $channel, (bool) $on);
            }
        }
        $user->forceFill([
            'quiet_hours_start' => $data['quiet_start'] ?? null,
            'quiet_hours_end' => $data['quiet_end'] ?? null,
            'email_digest' => (bool) ($data['digest'] ?? false),
        ])->save();

        return back()->with('status', 'notification-settings-saved');
    }

    public function subscribe(Request $request): HttpResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'starts_with:https://', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
        ]);
        PushSubscription::query()->updateOrCreate(['endpoint_hash' => hash('sha256', (string) $data['endpoint'])], [
            'user_id' => $this->user($request)->id,
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'label' => mb_substr((string) $request->userAgent(), 0, 120),
        ]);

        return response()->noContent();
    }

    public function unsubscribe(Request $request): RedirectResponse
    {
        PushSubscription::query()->where('user_id', $this->user($request)->id)->delete();

        return back()->with('status', 'push-turned-off');
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
