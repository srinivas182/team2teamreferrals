<?php

/*
 * Go-live (Sprint 54a): the health command the deploy and the server watch rely on, the ops records for backups and
 * restore drills, the alert email, and the parts of the deploy kit that can be checked without a server. The deploy
 * itself (first deploy, zero-downtime switch, automatic rollbacks, backups and the restore drill) is rehearsed for
 * real in CI: scripts/ci/deploy-rehearsal.sh.
 */

use App\Domains\Platform\Console\HealthCommand;
use App\Domains\Platform\Console\OpsRecordCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => Cache::flush());

function health(array $skip = []): array
{
    $code = Artisan::call('t2t:health', ['--json' => true, '--skip' => $skip]);

    return [$code, json_decode(Artisan::output(), true)];
}

it('says the deployment is healthy only when the scheduler is running, and names what is not', function () {
    [$code, $out] = health();
    expect($code)->toBe(1)->and($out['ok'])->toBeFalse()
        ->and($out['checks']['scheduler'])->toMatchArray(['ok' => false, 'detail' => 'No heartbeat yet.'])
        ->and($out['checks']['database']['ok'])->toBeTrue()
        ->and($out['checks']['migrations'])->toMatchArray(['ok' => true])
        ->and($out['checks']['disk']['ok'])->toBeTrue()
        // Not used in tests: reported as not applicable, never as failing.
        ->and($out['checks']['workers']['ok'])->toBeNull()
        ->and($out['checks']['ssr']['ok'])->toBeNull()
        ->and($out['checks']['scanner']['ok'])->toBeNull()
        ->and($out['checks']['backup']['ok'])->toBeNull();

    HealthCommand::beat();
    expect(health()[0])->toBe(0);
    // A scheduler that stopped more than three minutes ago fails the check.
    $this->travel(4)->minutes();
    expect(health()[1]['checks']['scheduler']['ok'])->toBeFalse();
    // The deploy leaves the scheduler out (it has just been restarted).
    expect(health(['scheduler'])[0])->toBe(0);
});

it('notices pending migrations', function () {
    DB::table('migrations')->where('migration', DB::table('migrations')->max('migration'))->delete();
    [$code, $out] = health(['scheduler']);
    expect($code)->toBe(1)->and($out['checks']['migrations']['ok'])->toBeFalse()->and($out['checks']['migrations']['detail'])->toStartWith('1 pending');
});

it('fails when the nightly backup stops, from the first one recorded', function () {
    HealthCommand::beat();
    $this->artisan('t2t:ops:record', ['kind' => 'backup', 'status' => 'failed', 'detail' => 'all/daily'])->assertSuccessful();
    expect(health()[1]['checks']['backup'])->toMatchArray(['ok' => false, 'detail' => 'No backup has succeeded.']);

    $this->artisan('t2t:ops:record', ['kind' => 'backup', 'status' => 'ok', 'detail' => 'all/daily, database 120 KB'])->assertSuccessful();
    [$code, $out] = health();
    expect($code)->toBe(0)->and($out['checks']['backup']['ok'])->toBeTrue();
    expect(OpsRecordCommand::last('backup'))->toMatchArray(['status' => 'ok', 'detail' => 'all/daily, database 120 KB']);

    // A failed run doesn't hide that the last good one is recent…
    $this->artisan('t2t:ops:record', ['kind' => 'backup', 'status' => 'failed']);
    expect(health()[1]['checks']['backup']['ok'])->toBeTrue();
    // …but a day and more without a good one fails.
    $this->travel(27)->hours();
    HealthCommand::beat();
    expect(health()[1]['checks']['backup'])->toMatchArray(['ok' => false, 'detail' => 'Last good backup 27 hours ago.']);

    $this->artisan('t2t:ops:record', ['kind' => 'nonsense', 'status' => 'ok'])->assertExitCode(2);
    $this->artisan('t2t:ops:record', ['kind' => 'restore-drill', 'status' => 'maybe'])->assertExitCode(2);
});

it('emails operations alerts to ALERT_EMAIL straight away, and does nothing without one', function () {
    config(['mail.default' => 'array']);
    $this->artisan('t2t:ops:alert', ['title' => 'Something is not working', 'detail' => 'workers (Horizon is not running.)'])->assertSuccessful();
    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0);

    config(['services.ops.alert_email' => 'ops@example.com']);
    $this->artisan('t2t:ops:alert', ['title' => 'Something is not working', 'detail' => 'workers (Horizon is not running.)'])->assertSuccessful();
    $sent = app('mailer')->getSymfonyTransport()->messages();
    expect($sent)->toHaveCount(1);
    $email = $sent->first()->getOriginalMessage();
    expect($email->getTo()[0]->getAddress())->toBe('ops@example.com')
        ->and($email->getSubject())->toContain('Something is not working')
        ->and($email->getTextBody())->toContain('Horizon is not running.')->toContain('docs/operations/runbooks.md');
});

it('runs the scheduler heartbeat every minute', function () {
    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('health:scheduler-beat');
});

it('gives every table a primary key, which DigitalOcean Managed MySQL requires', function () {
    $tables = collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"))->pluck('name');
    $without = $tables->filter(fn ($t) => collect(DB::select("pragma table_info(\"{$t}\")"))->every(fn ($c) => (int) $c->pk === 0))->values()->all();
    expect($tables->count())->toBeGreaterThan(50)->and($without)->toBe([]);
});

it('ships a deploy kit whose settings are all documented and whose scripts are executable', function () {
    $root = base_path('deploy');
    $example = (string) file_get_contents("{$root}/env.example");
    $used = [];
    foreach (glob("{$root}/bin/*.sh") as $script) {
        expect(is_executable($script))->toBeTrue("{$script} isn't executable");
        preg_match_all('/envval ([A-Z0-9_]+)/', (string) file_get_contents($script), $m);
        $used = [...$used, ...$m[1]];
    }
    $undocumented = array_values(array_filter(array_unique($used), fn ($k) => ! preg_match('/^#? ?'.$k.'=/m', $example)));
    expect($undocumented)->toBe([]);

    // The compose file reads the same settings the scripts expect, and the deploy and set-up are documented.
    $compose = (string) file_get_contents("{$root}/compose.yaml");
    expect($compose)->toContain('${T2T_IMAGE:?')->toContain('MEILISEARCH_KEY:?')->toContain('./secrets/db:/run/t2t/db:ro');
    foreach (['digitalocean-setup.md', 'deploy.md', 'runbooks.md', 'disaster-recovery.md'] as $doc) {
        expect(file_exists(base_path("docs/operations/{$doc}")))->toBeTrue("docs/operations/{$doc} is missing");
    }
    $runbooks = (string) file_get_contents(base_path('docs/operations/runbooks.md'));
    foreach (['deploy.sh', 'backup.sh', 'restore-drill.sh', 'watch.sh', 't2t:health'] as $mentioned) {
        expect($runbooks)->toContain($mentioned);
    }
});

it('can show the first admin their set-password link in the terminal, before email is set up', function () {
    config(['mail.default' => 'array']);
    Artisan::call('t2t:super-admin', ['email' => 'owner@example.com', '--show-link' => true]);
    $out = Artisan::output();
    expect($out)->toContain('Set your password here: http')->toContain('owner%40example.com');
    preg_match('/Set your password here: (\S+)/', $out, $m);
    $this->get(parse_url($m[1], PHP_URL_PATH).'?'.parse_url($m[1], PHP_URL_QUERY))->assertOk();
    // Without the option the link isn't printed.
    Artisan::call('t2t:super-admin', ['email' => 'owner@example.com']);
    expect(Artisan::output())->not->toContain('Set your password here');
});

it('never redirects health checks to the canonical address (they come from inside the server over plain HTTP)', function () {
    config(['seo.force_canonical' => true, 'seo.canonical_url' => 'https://teamtoteamreferrals.com']);
    $this->get('http://127.0.0.1:8000/up')->assertOk();
    $this->get('http://127.0.0.1:8000/health')->assertOk();
    $this->get('http://127.0.0.1:8000/about')->assertRedirect('https://teamtoteamreferrals.com/about');
});

it('reads the cache and the scheduler heartbeat correctly from Redis, which returns numbers as strings', function () {
    try {
        Illuminate\Support\Facades\Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('No Redis here.');
    }
    config(['cache.default' => 'redis']);
    Cache::store('redis')->flush();
    HealthCommand::beat();
    [$code, $out] = health();
    expect($out['checks']['cache']['ok'])->toBeTrue()
        ->and($out['checks']['scheduler']['ok'])->toBeTrue()
        ->and($code)->toBe(0);
    Cache::store('redis')->flush();
});

it('trusts the proxy in front for the scheme before deciding on a canonical redirect (no https redirect loop behind Caddy)', function () {
    config(['seo.force_canonical' => true, 'seo.canonical_url' => 'https://teamtoteamreferrals.com']);
    // As Caddy passes it on: plain HTTP to Octane, with the visitor's scheme in X-Forwarded-Proto.
    $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])->get('http://teamtoteamreferrals.com/')->assertOk();
    $this->withHeaders(['X-Forwarded-Proto' => 'http'])->get('http://teamtoteamreferrals.com/')->assertRedirect('https://teamtoteamreferrals.com/');
});
