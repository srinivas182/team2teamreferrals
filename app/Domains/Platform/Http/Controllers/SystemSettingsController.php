<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Phone\PhoneNumber;
use App\Domains\Platform\Deliveries\ProviderHealth;
use App\Domains\Platform\Notifications\TestEmailNotification;
use App\Domains\Platform\Providers\ProviderCatalogue;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\SeoData;
use App\Support\Sms\SmsSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Admin → System: providers and platform details, with test sends and live health. */
final class SystemSettingsController
{
    public function __construct(private readonly Settings $settings) {}

    public function show(?string $tab = null): Response
    {
        $s = $this->settings;
        $channel = fn (string $c) => [
            'driver' => $s->get("{$c}.driver", 'log'),
            'drivers' => collect(ProviderCatalogue::forChannel($c))->map(fn ($p, $key) => ['key' => $key, 'label' => $p['label'], 'fields' => collect($p['fields'])->map(fn ($f, $name) => [
                'name' => $name, 'label' => $f['label'], 'secret' => $f['secret'] ?? false, 'required' => $f['required'] ?? false, 'hint' => $f['hint'] ?? null,
                'value' => ($f['secret'] ?? false) ? null : $s->get("{$c}.{$key}.{$name}"),
                'saved' => ($f['secret'] ?? false) ? $s->masked("{$c}.{$key}.{$name}") : null,
            ])->values()])->values(),
            'health' => ProviderHealth::for($c),
        ];

        return Inertia::render('admin/system/Index', [
            'tab' => $tab ?? 'general',
            'general' => [
                'company_name' => $s->get('general.company_name'),
                'support_email' => $s->get('general.support_email'),
                'support_phone' => $s->get('general.support_phone'),
            ],
            'email' => [...$channel('mail'), 'from_address' => $s->get('mail.from_address', (string) config('mail.from.address')), 'from_name' => $s->get('mail.from_name', (string) config('mail.from.name'))],
            'sms' => $channel('sms'),
            'status' => session('status'),
            'seo' => SeoData::private('System settings')->toArray(),
        ]);
    }

    public function updateGeneral(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['nullable', 'string', 'max:120'],
            'support_email' => ['nullable', 'email:rfc', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:30'],
        ]);
        $values = [];
        foreach ($data as $key => $value) {
            $values['general.'.$key] = is_string($value) ? $value : null;
        }
        $this->save('general', $values, [], $request);

        return back()->with('status', 'settings-saved');
    }

    public function updateEmail(Request $request): RedirectResponse
    {
        $driver = (string) $request->validate(['driver' => ['required', Rule::in(ProviderCatalogue::drivers('mail'))]])['driver'];
        $base = $request->validate([
            'from_address' => ['required', 'email:rfc', 'max:255'],
            'from_name' => ['required', 'string', 'max:120'],
        ]);
        $values = ['mail.driver' => $driver, 'mail.from_address' => $base['from_address'], 'mail.from_name' => $base['from_name']];

        return $this->saveProvider('mail', $driver, $values, $request);
    }

    public function updateSms(Request $request): RedirectResponse
    {
        $driver = (string) $request->validate(['driver' => ['required', Rule::in(ProviderCatalogue::drivers('sms'))]])['driver'];

        return $this->saveProvider('sms', $driver, ['sms.driver' => $driver], $request);
    }

    public function testEmail(Request $request): RedirectResponse
    {
        $to = (string) $request->validate(['to' => ['required', 'email:rfc']])['to'];
        try {
            Notification::route('mail', $to)->notifyNow(new TestEmailNotification);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['to' => 'The provider refused the email: '.mb_substr($e->getMessage(), 0, 300)]);
        }

        return back()->with('status', 'test-email-sent');
    }

    public function testSms(Request $request, SmsSender $sms): RedirectResponse
    {
        $to = PhoneNumber::normalize((string) $request->input('to'));
        if ($to === null) {
            throw ValidationException::withMessages(['to' => 'Enter a valid US mobile number.']);
        }
        try {
            $sms->send($to, 'Team to Team test message: your SMS provider is working.');
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['to' => 'The provider refused the text: '.mb_substr($e->getMessage(), 0, 300)]);
        }

        return back()->with('status', 'test-sms-sent');
    }

    /** @param array<string, ?string> $values */
    private function saveProvider(string $channel, string $driver, array $values, Request $request): RedirectResponse
    {
        $secrets = [];
        $rules = [];
        foreach (ProviderCatalogue::fields($channel, $driver) as $name => $field) {
            $key = "{$channel}.{$driver}.{$name}";
            $isSecret = $field['secret'] ?? false;
            // A blank secret keeps the saved one, so admins never have to retype keys to change other fields.
            $required = ($field['required'] ?? false) && ! ($isSecret && $this->settings->has($key));
            $rules["fields.{$name}"] = [$required ? 'required' : 'nullable', 'string', 'max:500'];
            if ($isSecret) {
                $secrets[] = $key;
            }
        }
        $data = $request->validate($rules, ['fields.*.required' => 'This field is required for the selected provider.']);
        foreach (ProviderCatalogue::fields($channel, $driver) as $name => $field) {
            $value = $data['fields'][$name] ?? null;
            $key = "{$channel}.{$driver}.{$name}";
            if (($field['secret'] ?? false) && ($value === null || $value === '')) {
                continue;
            }
            $values[$key] = $value;
        }
        $this->save($channel, $values, $secrets, $request);

        return back()->with('status', 'settings-saved');
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $secrets
     */
    private function save(string $section, array $values, array $secrets, Request $request): void
    {
        /** @var User $actor */
        $actor = $request->user();
        $changes = [];
        foreach ($values as $key => $value) {
            $value = $value === null ? null : (string) $value;
            $secret = in_array($key, $secrets, true);
            $before = $this->settings->get($key);
            if ($before === $value) {
                continue;
            }
            $this->settings->set($key, $value, $secret, $actor->id);
            $changes[$key] = $secret ? 'changed (hidden)' : ['from' => $before, 'to' => $value];
        }
        if ($changes !== []) {
            activity('admin')->causedBy($actor)->event('settings-changed')->withProperties(['section' => $section, 'changes' => $changes])
                ->log('Changed '.$section.' settings');
        }
    }
}
