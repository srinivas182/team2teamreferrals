<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Phone\PhoneNumber;
use App\Domains\Platform\Ai\AiProvider;
use App\Domains\Platform\Deliveries\ProviderHealth;
use App\Domains\Platform\Modules\Modules;
use App\Domains\Platform\Notifications\TestEmailNotification;
use App\Domains\Platform\Providers\ProviderCatalogue;
use App\Domains\Platform\Providers\ProviderChecks;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\SeoData;
use App\Support\Sms\SmsSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'tab' => $tab ?? 'overview',
            'general' => [
                'company_name' => $s->get('general.company_name'),
                'support_email' => $s->get('general.support_email'),
                'support_phone' => $s->get('general.support_phone'),
            ],
            'email' => [...$channel('mail'), 'from_address' => $s->get('mail.from_address', (string) config('mail.from.address')), 'from_name' => $s->get('mail.from_name', (string) config('mail.from.name'))],
            'sms' => $channel('sms'),
            'payments' => $channel('payments'),
            'storage' => $channel('storage'),
            'cdn' => $channel('cdn'),
            'ai' => [...$channel('ai'), 'spent' => app(AiProvider::class)->spentThisMonth()],
            'aiFallback' => $channel('ai_fallback'),
            'modules' => collect(Modules::ALL)->map(fn ($m, $k) => ['key' => $k, 'label' => $m['label'], 'affects' => $m['affects'], 'enabled' => app(Modules::class)->enabled($k)])->values(),
            'overview' => collect(ProviderChecks::PROVIDERS)->map(fn ($label, $k) => ['key' => $k, 'label' => $label, 'driver' => $s->get($k.'.driver'), 'last' => app(ProviderChecks::class)->last()[$k] ?? null, 'health' => in_array($k, ['mail', 'sms'], true) ? ProviderHealth::for($k) : null])->values(),
            'maintenance' => $s->get('platform.maintenance') === '1',
            'checks' => session('checks'),
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
        return $this->updateChannel($request, 'sms');
    }

    /** Payments, storage and AI share one handler: pick a driver, fill its fields. */
    public function updateChannel(Request $request, string $channel): RedirectResponse
    {
        abort_unless(in_array($channel, ['sms', 'payments', 'storage', 'ai', 'ai_fallback', 'cdn'], true), 404);
        $driver = (string) $request->validate(['driver' => ['required', Rule::in(ProviderCatalogue::drivers($channel))]])['driver'];

        return $this->saveProvider($channel, $driver, ["{$channel}.driver" => $driver], $request);
    }

    public function check(Request $request, ProviderChecks $checks, string $provider): RedirectResponse
    {
        abort_unless(isset(ProviderChecks::PROVIDERS[$provider]), 404);

        return back()->with('checks', [$provider => $checks->check($provider, $request->user()?->getAttribute('email'))]);
    }

    public function checkAll(Request $request, ProviderChecks $checks): RedirectResponse
    {
        $results = $checks->all($request->user()?->getAttribute('email'));
        activity('admin')->causedBy($request->user())->event('providers-tested')->withProperties(collect($results)->map(fn ($r) => $r['ok'])->all())->log('Tested every provider');

        return back()->with('checks', $results);
    }

    public function updateModules(Request $request): RedirectResponse
    {
        $data = $request->validate(['modules' => ['required', 'array'], 'modules.*' => ['boolean']]);
        $values = [];
        foreach (array_keys(Modules::ALL) as $k) {
            if (array_key_exists($k, $data['modules'])) {
                $values['modules.'.$k] = $data['modules'][$k] ? '1' : '0';
            }
        }
        $this->save('modules', $values, [], $request);

        return back()->with('status', 'settings-saved');
    }

    public function maintenance(Request $request): RedirectResponse
    {
        $on = $request->boolean('on');
        $this->save('maintenance', ['platform.maintenance' => $on ? '1' : '0'], [], $request);

        return back()->with('status', $on ? 'maintenance-on' : 'maintenance-off');
    }

    /** Non-secret settings as JSON, to copy a staging setup to production. Secrets are never included. */
    public function export(Request $request): JsonResponse
    {
        $rows = DB::table('settings')->where('is_secret', false)->where('key', 'not like', 'health.%')->where('key', 'not like', 'push.%')->orderBy('key')->pluck('value', 'key');
        activity('admin')->causedBy($request->user())->event('settings-exported')->log('Exported platform settings');

        return response()->json(['exported_at' => now()->toIso8601String(), 'app_url' => config('app.url'), 'settings' => $rows], 200, [
            'Content-Disposition' => 'attachment; filename="t2t-settings-'.now()->format('Y-m-d').'.json"',
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:256']]);
        $json = json_decode((string) file_get_contents((string) $request->file('file')?->getRealPath()), true);
        $settings = is_array($json) && is_array($json['settings'] ?? null) ? $json['settings'] : null;
        if ($settings === null) {
            throw ValidationException::withMessages(['file' => 'That isn’t a settings export from Team to Team.']);
        }
        $secretKeys = DB::table('settings')->where('is_secret', true)->pluck('key')->all();
        $values = [];
        foreach ($settings as $key => $value) {
            $key = (string) $key;
            // Only known, non-secret keys: anything else in the file is ignored.
            if (! preg_match('/^(general|mail|sms|payments|storage|ai|modules|platform)\.[a-z0-9_.]+$/', $key) || in_array($key, $secretKeys, true) || $this->isSecretField($key)) {
                continue;
            }
            $values[$key] = is_scalar($value) ? (string) $value : null;
        }
        $this->save('import', $values, [], $request);

        return back()->with('status', 'settings-imported');
    }

    private function isSecretField(string $key): bool
    {
        $parts = explode('.', $key);
        if (count($parts) !== 3) {
            return false;
        }

        return (ProviderCatalogue::fields($parts[0], $parts[1])[$parts[2]]['secret'] ?? false) === true;
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
