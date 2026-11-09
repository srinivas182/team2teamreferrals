<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Http;

use App\Domains\Messaging\Models\NotificationTemplate;
use App\Domains\Messaging\NotificationCatalogue;
use App\Domains\Messaging\Templates;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Industries → Messages: this industry's own wording for each notification, inheriting the platform's by default. */
final class AdminIndustryMessagesController
{
    public const EDITABLE = ['mail', 'sms', 'database'];

    public function __construct(private readonly Templates $templates) {}

    public function show(Request $request, Industry $industry): Response
    {
        $type = (string) $request->query('type', 'referral.waiting');
        abort_unless(isset(NotificationCatalogue::TYPES[$type]), 404);
        $channels = array_values(array_intersect(NotificationCatalogue::TYPES[$type]['channels'], self::EDITABLE));

        return Inertia::render('admin/industries/Messages', [
            'industry' => ['slug' => $industry->slug, 'name' => $industry->name, 'tier' => $industry->tier->value, 'terms' => $industry->terms()],
            'types' => collect(NotificationCatalogue::TYPES)->map(fn ($d, $k) => [
                'key' => $k, 'group' => $d['group'], 'label' => $d['label'],
                'overridden' => NotificationTemplate::query()->where(['type' => $k, 'industry_id' => $industry->id, 'is_active' => true])->exists(),
            ])->values(),
            'type' => ['key' => $type, 'label' => NotificationCatalogue::TYPES[$type]['label'], 'description' => NotificationCatalogue::TYPES[$type]['description']],
            'channels' => collect($channels)->map(fn (string $c) => [
                'channel' => $c,
                'label' => NotificationCatalogue::CHANNELS[$c],
                'current' => $this->templates->wording($type, $c, $industry->id),
                'inherited' => $this->templates->wording($type, $c),
                'history' => $this->templates->scoped($type, $c, $industry->id)->orderByDesc('version')->limit(20)->get()
                    ->map(fn (NotificationTemplate $t) => ['id' => $t->id, 'version' => $t->version, 'active' => $t->is_active, 'when' => $t->created_at->toDayDateTimeString()]),
            ]),
            'sample' => self::sample($industry),
            'mergeFields' => ['client', 'city', 'team', 'hours', 'days', 'month', 'next_month', 'date', 'pro', 'pros', 'client_term', 'clients_term', 'referral_term', 'fee_term', 'url'],
            'status' => session('status'),
            'seo' => SeoData::private($industry->name.' messages')->toArray(),
        ]);
    }

    public function save(Request $request, Industry $industry): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(NotificationCatalogue::TYPES))],
            'channel' => ['required', Rule::in(self::EDITABLE)],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:2000'],
        ]);
        abort_unless(in_array($data['channel'], NotificationCatalogue::TYPES[$data['type']]['channels'], true), 422);
        if ($data['channel'] === 'sms' && mb_strlen((string) $data['body']) > 320) {
            return back()->withErrors(['body' => 'Keep texts under 320 characters (two SMS segments).']);
        }
        $t = $this->templates->save((string) $data['type'], (string) $data['channel'], $data['subject'] ?? null, (string) $data['body'], $request->user()?->getKey(), $industry->id);
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('industry-message-saved')->withProperties(['type' => $t->type, 'channel' => $t->channel, 'version' => $t->version])->log("Saved {$industry->name} {$t->type} {$t->channel} wording v{$t->version}");

        return back()->with('status', 'template-saved');
    }

    public function activate(Request $request, Industry $industry, NotificationTemplate $template): RedirectResponse
    {
        abort_unless($template->industry_id === $industry->id, 404);
        $this->templates->activate($template);
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('industry-message-restored')->withProperties(['type' => $template->type, 'channel' => $template->channel, 'version' => $template->version])->log("Restored {$industry->name} {$template->type} wording v{$template->version}");

        return back()->with('status', 'template-restored');
    }

    public function reset(Request $request, Industry $industry): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(NotificationCatalogue::TYPES))], 'channel' => ['required', Rule::in(self::EDITABLE)]]);
        $this->templates->reset((string) $data['type'], (string) $data['channel'], $industry->id);
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('industry-message-reset')->withProperties($data)->log("{$industry->name} {$data['type']} now uses the platform wording");

        return back()->with('status', 'industry-message-reset');
    }

    /**
     * Every message this industry sends, as members will read it, for the counsel review pack.
     *
     * @return list<array{label: string, channel: string, source: string, subject: string, body: string}>
     */
    public static function pack(Industry $industry, Templates $templates): array
    {
        $out = [];
        $sample = self::sample($industry);
        foreach (NotificationCatalogue::TYPES as $type => $def) {
            foreach (array_intersect($def['channels'], self::EDITABLE) as $channel) {
                $w = $templates->wording($type, $channel, $industry->id);
                $out[] = ['label' => $def['label'], 'channel' => NotificationCatalogue::CHANNELS[$channel], 'source' => $w['source'],
                    'subject' => $channel === 'sms' ? '' : NotificationCatalogue::render($w['subject'], $sample), 'body' => NotificationCatalogue::render($w['body'], $sample)];
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    public static function sample(Industry $industry): array
    {
        return [...$industry->mergeTerms(), 'first_name' => 'Jordan', 'client' => 'Tom Brennan', 'city' => 'Houston', 'industry' => strtolower($industry->name), 'team' => 'Bayou City Homes',
            'hours' => '24', 'days' => '7', 'month' => 'March', 'next_month' => 'April', 'date' => 'Feb 9, 2027', 'url' => 'https://teamtoteamreferrals.com/app', 'body' => 'Message from your network owner.'];
    }
}
