<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Http\Controllers\Admin;

use App\Domains\Messaging\Models\NotificationTemplate;
use App\Domains\Messaging\NotificationCatalogue;
use App\Domains\Messaging\Templates;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Templates: edit wording per notification and channel, preview it, restore any earlier version. */
final class TemplatesController
{
    public function __construct(private readonly Templates $templates) {}

    public function index(): Response
    {
        $edited = NotificationTemplate::query()->where('is_active', true)->get(['type', 'channel', 'version']);

        return Inertia::render('admin/templates/Index', [
            'types' => collect(NotificationCatalogue::TYPES)->map(fn ($d, $key) => [
                'key' => $key, 'group' => $d['group'], 'label' => $d['label'], 'channels' => $d['channels'],
                'edited' => $edited->where('type', $key)->pluck('version', 'channel'),
            ])->values(),
            'seo' => SeoData::private('Templates')->toArray(),
        ]);
    }

    public function edit(string $type): Response
    {
        abort_unless(isset(NotificationCatalogue::TYPES[$type]), 404);
        $def = NotificationCatalogue::TYPES[$type];

        return Inertia::render('admin/templates/Edit', [
            'type' => ['key' => $type, 'label' => $def['label'], 'description' => $def['description'], 'urgent' => $def['urgent'], 'locked' => $def['locked']],
            'channels' => collect(array_values(array_diff($def['channels'], ['push'])))->map(fn (string $c) => [
                'channel' => $c,
                'label' => NotificationCatalogue::CHANNELS[$c],
                'current' => $this->templates->wording($type, $c),
                'history' => NotificationTemplate::query()->where(['type' => $type, 'channel' => $c])->orderByDesc('version')->limit(20)->get()
                    ->map(fn (NotificationTemplate $t) => ['id' => $t->id, 'version' => $t->version, 'subject' => $t->subject, 'body' => $t->body, 'active' => $t->is_active, 'when' => $t->created_at->toDayDateTimeString()]),
            ]),
            'mergeFields' => ['first_name', 'client', 'city', 'industry', 'team', 'hours', 'days', 'month', 'next_month', 'date', 'url'],
            'sample' => ['first_name' => 'Jordan', 'client' => 'Tom Brennan', 'city' => 'Houston', 'industry' => 'real estate', 'team' => 'Bayou City Homes', 'hours' => 24, 'days' => 7, 'month' => 'March', 'next_month' => 'April', 'date' => 'Feb 9, 2027', 'url' => url('/app')],
            'status' => session('status'),
            'seo' => SeoData::private('Edit template')->toArray(),
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        abort_unless(isset(NotificationCatalogue::TYPES[$type]), 404);
        $data = $request->validate([
            'channel' => ['required', Rule::in(NotificationCatalogue::TYPES[$type]['channels'])],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:2000'],
        ]);
        if ($data['channel'] === 'sms' && mb_strlen((string) $data['body']) > 320) {
            return back()->withErrors(['body' => 'Keep texts under 320 characters (two SMS segments).']);
        }
        $t = $this->templates->save($type, (string) $data['channel'], $data['subject'] ?? null, (string) $data['body'], $request->user()?->getKey());
        activity('admin')->causedBy($request->user())->event('template-saved')->withProperties(['type' => $type, 'channel' => $t->channel, 'version' => $t->version])->log("Saved {$type} {$t->channel} template v{$t->version}");

        return back()->with('status', 'template-saved');
    }

    public function activate(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $this->templates->activate($template);
        activity('admin')->causedBy($request->user())->event('template-restored')->withProperties(['type' => $template->type, 'channel' => $template->channel, 'version' => $template->version])->log("Restored {$template->type} {$template->channel} template v{$template->version}");

        return back()->with('status', 'template-restored');
    }

    public function reset(Request $request, string $type, string $channel): RedirectResponse
    {
        abort_unless(isset(NotificationCatalogue::TYPES[$type]), 404);
        $this->templates->reset($type, $channel);
        activity('admin')->causedBy($request->user())->event('template-reset')->withProperties(['type' => $type, 'channel' => $channel])->log("Reset {$type} {$channel} template to default");

        return back()->with('status', 'template-reset');
    }
}
