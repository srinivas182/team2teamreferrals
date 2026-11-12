<?php

use App\Support\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/** Captures text messages instead of sending them. */
final class FakeSms implements SmsSender
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public function send(string $toE164, string $message): ?string
    {
        $this->sent[] = ['to' => $toE164, 'message' => $message];

        return null;
    }

    public function lastCode(): ?string
    {
        $last = end($this->sent);

        return $last && preg_match('/\b(\d{6})\b/', $last['message'], $m) ? $m[1] : null;
    }
}

function fakeSms(): FakeSms
{
    $fake = new FakeSms;
    app()->instance(SmsSender::class, $fake);

    return $fake;
}

/** Switch modules on, as an admin would in Admin → Settings → Modules (the AI modules are off by default). */
function modulesOn(string ...$keys): void
{
    foreach ($keys as $k) {
        app(App\Domains\Platform\Settings\Settings::class)->set('modules.'.$k, '1');
    }
}
