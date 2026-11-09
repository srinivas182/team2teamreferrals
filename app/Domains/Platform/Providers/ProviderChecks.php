<?php

declare(strict_types=1);

namespace App\Domains\Platform\Providers;

use App\Domains\Platform\Ai\AiProvider;
use App\Domains\Platform\Files\PrivateFiles;
use App\Domains\Platform\Notifications\TestEmailNotification;
use App\Domains\Platform\Payments\Stripe;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Live checks for every provider. Results are kept so the System overview shows the last outcome. */
final class ProviderChecks
{
    public const PROVIDERS = ['mail' => 'Email', 'sms' => 'Text messages', 'payments' => 'Payments', 'storage' => 'Storage', 'ai' => 'AI'];

    public function __construct(private readonly Settings $settings, private readonly Stripe $stripe, private readonly AiProvider $ai) {}

    /** @return array{ok: bool|null, message: string, at: string} ok=null means not configured */
    public function check(string $provider, ?string $email = null): array
    {
        try {
            $result = match ($provider) {
                'mail' => $this->mail($email),
                'sms' => $this->sms(),
                'payments' => $this->payments(),
                'storage' => $this->storage(),
                'ai' => $this->aiCheck(),
                default => [null, 'Unknown provider'],
            };
        } catch (Throwable $e) {
            $result = [false, mb_substr($e->getMessage(), 0, 300)];
        }
        $out = ['ok' => $result[0], 'message' => $result[1], 'at' => now()->toIso8601String()];
        $all = $this->last();
        $all[$provider] = $out;
        $this->settings->set('health.last_checks', (string) json_encode($all));

        return $out;
    }

    /** @return array<string, array{ok: bool|null, message: string, at: string}> */
    public function all(?string $email = null): array
    {
        $out = [];
        foreach (array_keys(self::PROVIDERS) as $p) {
            $out[$p] = $this->check($p, $email);
        }

        return $out;
    }

    /** @return array<string, array{ok: bool|null, message: string, at: string}> */
    public function last(): array
    {
        $raw = json_decode((string) $this->settings->get('health.last_checks', '{}'), true);

        return is_array($raw) ? $raw : [];
    }

    /** @return array{0: bool|null, 1: string} */
    private function mail(?string $email): array
    {
        $driver = $this->settings->get('mail.driver', 'log');
        if ($driver === 'log') {
            return [null, 'Development log: no email leaves the server.'];
        }
        if ($email === null) {
            return [null, 'No address to send a test to.'];
        }
        Notification::route('mail', $email)->notifyNow(new TestEmailNotification);

        return [true, "Test email sent to {$email} via ".strtoupper((string) $driver).'.'];
    }

    /** @return array{0: bool|null, 1: string} */
    private function sms(): array
    {
        if ($this->settings->get('sms.driver', 'log') !== 'twilio') {
            return [null, 'Development log: no text leaves the server.'];
        }
        $sid = (string) $this->settings->get('sms.twilio.sid');
        $res = Http::withBasicAuth($sid, (string) $this->settings->get('sms.twilio.token'))->timeout(10)->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");
        if ($res->failed()) {
            return [false, 'Twilio refused the credentials: '.($res->json('message') ?? $res->status())];
        }

        return [true, 'Connected to Twilio account '.($res->json('friendly_name') ?? $sid).' ('.($res->json('status') ?? 'active').').'];
    }

    /** @return array{0: bool|null, 1: string} */
    private function payments(): array
    {
        if (! $this->stripe->connected()) {
            return [null, 'Not connected.'];
        }
        $a = $this->stripe->account();
        $hook = $this->settings->has('payments.stripe.webhook_secret') ? '' : ' Add the webhook signing secret before billing goes live.';

        return [true, "Connected to {$a['name']} in {$a['mode']} mode.{$hook}"];
    }

    /** @return array{0: bool|null, 1: string} */
    private function storage(): array
    {
        $disk = Storage::disk(PrivateFiles::DISK);
        $path = '.health/'.Str::random(12).'.txt';
        $disk->put($path, 'ok');
        $read = $disk->get($path);
        $disk->delete($path);
        $where = config('filesystems.disks.private.driver') === 's3' ? 'bucket '.config('filesystems.disks.private.bucket') : 'this server';

        return $read === 'ok' ? [true, "Wrote, read and deleted a test file on {$where}."] : [false, 'The test file came back different.'];
    }

    /** @return array{0: bool|null, 1: string} */
    private function aiCheck(): array
    {
        if (! $this->ai->enabled()) {
            return [null, 'Off.'];
        }
        $r = $this->ai->complete('Reply with the single word: ready', 'health-check', 10);

        return [true, "{$r['model']} replied “".trim($r['text'])."” ({$r['input_tokens']}+{$r['output_tokens']} tokens, \$".number_format($r['cost_usd'], 4).').'];
    }
}
