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

/** Catch texts in a fake, with texting switched on (as with Twilio set up in Admin → System → Text messages). */
function fakeSms(): FakeSms
{
    textingOn();
    $fake = new FakeSms;
    app()->instance(SmsSender::class, $fake);

    return $fake;
}

/** Texting on: a provider set up, and not switched off. */
function textingOn(): void
{
    $s = app(App\Domains\Platform\Settings\Settings::class);
    foreach (['sms.driver' => 'twilio', 'sms.twilio.sid' => 'ACtest', 'sms.twilio.token' => 'token', 'sms.twilio.from' => '+14355550100'] as $k => $v) {
        $s->set($k, $v);
    }
}

/** Switch modules on, as an admin would in Admin → Settings → Modules (the AI modules are off by default). */
function modulesOn(string ...$keys): void
{
    foreach ($keys as $k) {
        app(App\Domains\Platform\Settings\Settings::class)->set('modules.'.$k, '1');
    }
}

/**
 * Let a test insert rows with placeholder foreign keys (only some columns matter to it). SQLite checks foreign keys
 * when the test's transaction ends, and it is rolled back; MySQL checks each insert, so its checks are switched off
 * for this connection, which the next test doesn't share.
 */
function deferForeignKeys(): void
{
    Illuminate\Support\Facades\DB::statement(Illuminate\Support\Facades\DB::getDriverName() === 'sqlite' ? 'PRAGMA defer_foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 0');
}

/** @return list<string> The database's tables, on SQLite or MySQL. */
function tableNames(): array
{
    return collect(Illuminate\Support\Facades\Schema::getTables())->pluck('name')->reject(fn ($t) => str_starts_with($t, 'sqlite_'))->values()->all();
}
