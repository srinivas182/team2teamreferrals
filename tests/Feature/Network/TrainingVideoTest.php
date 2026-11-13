<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Network\Training\Training;
use App\Domains\Network\Training\Video\VideoStudio;
use App\Domains\Platform\Ai\AiCredits;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    fakeSms();
    config(['filesystems.default' => 'public']);
    Storage::fake('public');
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    modulesOn('ai_videos');
    $s = app(Settings::class);
    $s->set('video.driver', 'heygen');
    $s->set('video.heygen.api_key', 'hg', secret: true);
    $s->set('video.heygen.price_per_minute', '1.50');
    $s->set('video.heygen.webhook_secret', 'whsec', secret: true);
    $s->set('ai.driver', 'anthropic');
    $s->set('ai.anthropic.api_key', 'k', secret: true);
    foreach (['model' => 'm', 'input_price' => '1', 'output_price' => '5', 'monthly_cap' => '100'] as $k => $v) {
        $s->set("ai.anthropic.{$k}", $v);
    }
    $this->renderStatus = 'completed';
    $this->renders = 0;
    Http::fake([
        'api.heygen.com/v2/avatars' => Http::response(['data' => ['avatars' => [['avatar_id' => 'Anna_public', 'avatar_name' => 'Anna']], 'talking_photos' => [['talking_photo_id' => 'someones-face']]]]),
        'api.heygen.com/v2/voices' => Http::response(['data' => ['voices' => [['voice_id' => 'v-en', 'name' => 'Clear', 'language' => 'English']]]]),
        'api.heygen.com/v2/video/generate' => function () {
            $this->renders++;

            return Http::response(['data' => ['video_id' => 'vid-'.$this->renders]]);
        },
        'api.heygen.com/v1/video_status.get*' => fn () => Http::response(['data' => ['status' => $this->renderStatus, 'video_url' => 'https://files.heygen.ai/v.mp4', 'caption_url' => 'https://files.heygen.ai/v.vtt', 'error' => ['message' => 'Render error']]]),
        'files.heygen.ai/v.mp4' => Http::response('MP4-BYTES'),
        'files.heygen.ai/v.vtt' => Http::response("WEBVTT\n\n00:00.000 --> 00:02.000\nWelcome"),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => str_repeat('Accept referrals within 24 hours and keep the referring team updated. ', 10)]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]),
    ]);
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $this->owner = User::factory()->create();
    Team::forLeader($this->owner);
    $this->g->owners()->attach($this->owner->id);
    $this->member = Team::forLeader(User::factory()->create());
    app(Seats::class)->approve(app(Seats::class)->apply($this->g, $this->member, $this->cities[1], 'L', null), null);
    $this->admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('super_admin');
    $this->script = str_repeat('Accept referrals within 24 hours and keep the referring team updated every week. ', 25); // ~350 words, 3 minutes
    $this->latest = fn () => DB::table('video_scripts')->latest('id')->first();
});

it('DONE WHEN: an approved script renders into a video published as the network’s training version', function () {
    $base = '/app/group/solar-installers-network/training-video';
    $this->actingAs($this->owner)->post($base.'/draft')->assertSessionHas('script_draft', fn ($t) => str_contains($t, '24 hours'));
    $this->post($base.'/scripts', ['title' => 'Welcome to our network', 'script' => $this->script])->assertSessionHas('status', 'video-script-saved');
    $s = ($this->latest)();
    $this->post("{$base}/scripts/{$s->ulid}/submit");
    $this->post("{$base}/scripts/{$s->ulid}/approve");
    $this->get($base)->assertInertia(fn ($p) => $p->component('training/VideoStudio')->where('scripts.0.estimate.minutes', 3)->where('scripts.0.estimate.credits', 150)->has('avatars', 1));
    $team = Team::of($this->owner);
    AiCredits::grantMonthly($team);
    DB::table('ai_credit_ledger')->insert(['team_id' => $team->id, 'kind' => 'purchase', 'credits' => 500, 'price_cents' => 1000, 'created_at' => now()]);
    $before = AiCredits::balance($team);
    $this->post("{$base}/scripts/{$s->ulid}/render", ['avatar' => 'Anna_public', 'voice' => 'v-en'])->assertSessionHas('status', 'video-render');
    expect(AiCredits::balance($team))->toBe($before - 150);
    app(VideoStudio::class)->poll();
    $s = ($this->latest)();
    expect($s->status)->toBe('ready')->and(Storage::disk('public')->get($s->video_path))->toBe('MP4-BYTES')->and(Storage::disk('public')->exists($s->captions_path))->toBeTrue();
    // A member passed earlier; publishing with "retake" means passing again for this network.
    $training = app(Training::class);
    $module = $training->module($this->g->industry);
    $training->submit($this->member->leader, $module, array_column(json_decode((string) $module->questions, true), 'answer'));
    $this->travel(1)->minutes();
    $this->post("{$base}/scripts/{$s->ulid}/publish", ['retake' => true])->assertSessionHas('status', 'video-publish');
    $this->travel(30)->days();
    expect($training->blocks($this->member->leader, $this->g->fresh()))->toBeTrue();
    $this->actingAs($this->member->leader)->get('/app/training/'.$this->g->industry->slug)->assertInertia(fn ($p) => $p->where('networkVideos.0.network', 'Solar Installers Network')
        ->where('networkVideos.0.transcript', trim($this->script))->where('networkVideos.0.captions', fn ($c) => str_ends_with((string) $c, '.vtt')));
    $training->submit($this->member->leader, $training->module($this->g->industry), array_column(json_decode((string) $module->questions, true), 'answer'));
    expect($training->blocks($this->member->leader, $this->g->fresh()))->toBeFalse();
});

it('publishes an admin video onto a lesson as a new training version, free, and rolls it back', function () {
    $industry = $this->g->industry;
    $base = '/admin/training/'.$industry->slug.'/videos';
    $module = app(Training::class)->module($industry);
    [$v0, $lessons0] = [(int) $module->version, (string) $module->lessons];
    $this->actingAs($this->admin)->post($base.'/scripts', ['title' => 'Consent', 'script' => $this->script])->assertSessionHasErrors('lesson');
    $this->post($base.'/scripts', ['title' => 'Consent', 'script' => $this->script, 'lesson' => 1]);
    $s = ($this->latest)();
    foreach (['submit', 'approve'] as $a) {
        $this->post("{$base}/scripts/{$s->ulid}/{$a}");
    }
    $this->post("{$base}/scripts/{$s->ulid}/render", ['avatar' => 'Anna_public', 'voice' => 'v-en']);
    expect((int) ($this->latest)()->credits)->toBe(0); // the platform pays
    app(VideoStudio::class)->poll();
    $this->post("{$base}/scripts/{$s->ulid}/publish", ['retake' => true]);
    $m = app(Training::class)->module($industry);
    $lesson = json_decode((string) $m->lessons, true)[1];
    expect((int) $m->version)->toBe($v0 + 1)->and((int) $m->min_version)->toBe($v0 + 1)->and($lesson['ai_presenter'])->toBeTrue()
        ->and($lesson['transcript'])->toBe(trim($this->script))->and($lesson['video_url'])->toContain('.mp4');
    $this->post("{$base}/scripts/{$s->ulid}/rollback")->assertSessionHas('status', 'video-rollback');
    $m = app(Training::class)->module($industry);
    expect(json_decode((string) $m->lessons, true))->toBe(json_decode($lessons0, true))->and((int) $m->version)->toBe($v0 + 2);
});

it('Tier B: refuses scripts that mention fees, and only a platform admin approves', function () {
    $m = Group::where('slug', 'mortgage-lenders-circle')->firstOrFail();
    $m->owners()->attach($this->owner->id);
    $base = '/app/group/mortgage-lenders-circle/training-video';
    $this->actingAs($this->owner)->post($base.'/scripts', ['title' => 'Fees', 'script' => $this->script.' You earn a referral fee on every closed loan.']);
    $s = ($this->latest)();
    $this->post("{$base}/scripts/{$s->ulid}/submit")->assertSessionHasErrors('script');
    $this->post("{$base}/scripts/{$s->ulid}", ['title' => 'No fees', 'script' => $this->script]);
    $this->post("{$base}/scripts/{$s->ulid}/submit");
    $this->post("{$base}/scripts/{$s->ulid}/approve")->assertForbidden();
    $this->actingAs($this->admin)->post("{$base}/scripts/{$s->ulid}/approve");
    expect(($this->latest)()->status)->toBe('approved');
});

it('retries a failed render once, then refunds the credits', function () {
    $base = '/app/group/solar-installers-network/training-video';
    $this->actingAs($this->owner)->post($base.'/scripts', ['title' => 'T', 'script' => $this->script]);
    $s = ($this->latest)();
    $this->post("{$base}/scripts/{$s->ulid}/submit");
    $this->post("{$base}/scripts/{$s->ulid}/approve");
    $team = Team::of($this->owner);
    AiCredits::grantMonthly($team);
    DB::table('ai_credit_ledger')->insert(['team_id' => $team->id, 'kind' => 'purchase', 'credits' => 500, 'price_cents' => 1000, 'created_at' => now()]);
    $before = AiCredits::balance($team);
    $this->post("{$base}/scripts/{$s->ulid}/render", ['avatar' => 'Anna_public', 'voice' => 'v-en']);
    $this->renderStatus = 'failed';
    app(VideoStudio::class)->poll();
    expect($this->renders)->toBe(2)->and(($this->latest)()->status)->toBe('rendering');
    $this->travel(5)->minutes();
    app(VideoStudio::class)->poll();
    expect(($this->latest)()->status)->toBe('failed')->and(AiCredits::balance($team))->toBe($before)->and($this->renders)->toBe(2);
});

it('uses stock presenters only, checks the callback signature, and respects permissions and switches', function () {
    $base = '/app/group/solar-installers-network/training-video';
    $this->actingAs($this->owner)->post($base.'/scripts', ['title' => 'T', 'script' => $this->script]);
    $s = ($this->latest)();
    $this->post("{$base}/scripts/{$s->ulid}/submit");
    $this->post("{$base}/scripts/{$s->ulid}/approve");
    $this->post("{$base}/scripts/{$s->ulid}/render", ['avatar' => 'someones-face', 'voice' => 'v-en'])->assertSessionHasErrors('avatar');
    $this->actingAs($this->member->leader)->get($base)->assertForbidden();
    $this->postJson('/webhooks/heygen', ['event_data' => ['video_id' => 'vid-1']], ['Signature' => 'wrong'])->assertForbidden();
    $body = json_encode(['event_type' => 'avatar_video.success', 'event_data' => ['video_id' => 'nothing']]);
    $this->call('POST', '/webhooks/heygen', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGNATURE' => hash_hmac('sha256', $body, 'whsec')], $body)->assertNoContent();
    app(Settings::class)->set('modules.training', '0');
    $this->actingAs($this->owner)->get($base)->assertNotFound(); // no training, no training videos
});

it('offers a free default script built from the rules, which passes the checks in open and restricted industries', function () {
    $open = $this->g->industry;
    $script = VideoStudio::defaultScript($open, $this->g);
    expect($script)->toContain('Solar Installers Network')->toContain('share of what the receiving team earns')->toContain('consent');
    expect(str_word_count($script))->toBeGreaterThan(150);
    $restricted = App\Domains\Network\Industries\Models\Industry::query()->where('tier', App\Domains\Network\Industries\Tier::Restricted)->firstOrFail();
    $b = VideoStudio::defaultScript($restricted, null);
    expect($b)->toContain('without any payment')->not->toMatch('/\b(referral fees?|fee|commission split|kickback|paid for referrals?)\b/i');
    $this->actingAs($this->admin)->get('/admin/training/'.$restricted->slug.'/videos')->assertInertia(fn ($p) => $p->where('defaultScript', $b));
    // It submits cleanly in the restricted industry (no fee talk), with no AI involved.
    $studio = app(VideoStudio::class);
    $ulid = $studio->create($this->admin, $restricted, null, 0, 'How referrals work here', $b);
    $studio->submit($this->admin, DB::table('video_scripts')->where('ulid', $ulid)->first());
    expect(DB::table('video_scripts')->where('ulid', $ulid)->value('status'))->toBe('submitted');
});
