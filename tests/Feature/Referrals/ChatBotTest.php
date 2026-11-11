<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Referrals\Bot\Bot;
use App\Domains\Referrals\Intake\IntakeEmails;
use App\Domains\Referrals\Referral;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    fakeSms();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $s = app(Settings::class);
    $s->set('sms.twilio.token', 'twilio-secret', secret: true);
    $s->set('ai.driver', 'anthropic');
    $s->set('ai.anthropic.api_key', 'k', secret: true);
    foreach (['model' => 'm', 'input_price' => '1', 'output_price' => '5', 'monthly_cap' => '100'] as $k => $v) {
        $s->set("ai.anthropic.{$k}", $v);
    }
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['client_name' => 'Maria Lopez', 'notes' => 'Wants rooftop solar soon.', 'city' => 'Hurricane, UT', 'network' => null])]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]);
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $sv = app(Seats::class);
    $this->a = Team::forLeader(User::factory()->create(['first_name' => 'Pat', 'phone' => '+14355550111', 'phone_verified_at' => now()]));
    $sv->approve($sv->apply($this->g, $this->a, $this->cities[2], 'L', null), null);
    $this->b = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Hurricane Sun']);
    $sv->approve($sv->apply($this->g, $this->b, $this->cities[1], 'L', null), null);
    // Sends a message to the webhook exactly as Twilio would, with its signature.
    $this->msg = function (string $body, string $from = 'whatsapp:+14355550111') {
        $params = ['From' => $from, 'Body' => $body, 'To' => 'whatsapp:+14355550000'];
        $url = url('/webhooks/twilio/inbound');
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }
        $res = $this->withHeaders(['X-Twilio-Signature' => base64_encode(hash_hmac('sha1', $data, 'twilio-secret', true))])->post('/webhooks/twilio/inbound', $params)->assertOk();

        return html_entity_decode(strip_tags((string) $res->getContent()));
    };
});

it('DONE WHEN: a member completes a referral entirely by WhatsApp and confirms it in one tap', function () {
    expect(($this->msg)('Hi'))->toContain('Hi Pat!');
    $reply = ($this->msg)('My neighbour Maria Lopez in Hurricane, UT wants rooftop solar soon.');
    expect($reply)->toBe('What’s their phone number or email?');
    $reply = ($this->msg)('(435) 555-0142');
    $form = collect(app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'])->keyBy('key');
    for ($i = 0; $i < 20 && ! str_contains($reply, '/bot/confirm/'); $i++) {
        $key = substr((string) DB::table('bot_sessions')->value('asking'), 7);
        $reply = ($this->msg)(match ($form[$key]['type']) {
            'choice' => '1', 'boolean' => 'yes', 'number' => '5', 'date' => 'next month', default => 'Some detail'
        });
    }
    expect($reply)->toContain('Ready to send your referral for Maria L.')->not->toContain('555-0142');
    preg_match('#https?://\S+/bot/confirm/\S+#', $reply, $m);
    $link = $m[0];
    $this->get($link)->assertOk()->assertInertia(fn ($p) => $p->component('BotConfirm')->where('summary.client', 'Maria Lopez')->where('summary.city', 'Hurricane, UT'));
    expect(Referral::count())->toBe(0); // nothing sent before the tap
    $this->post($link)->assertOk()->assertInertia(fn ($p) => $p->where('team', 'Hurricane Sun')->where('done', fn ($d) => is_string($d)));
    $r = Referral::first();
    expect($r->receiver_team_id)->toBe($this->b->id)->and($r->client_phone)->toBe('+14355550142')
        ->and(DB::table('bot_messages')->count())->toBe(0)->and(DB::table('intake_messages')->value('body'))->toBeNull();
    $this->post($link)->assertStatus(410); // once only
    $this->travel(31)->minutes();
    $this->get($link)->assertForbidden(); // expired
});

it('only lets verified member phones use the bot; everyone else gets the existing client flow', function () {
    $this->a->leader->forceFill(['phone_verified_at' => null])->save();
    expect(($this->msg)('My neighbour Maria Lopez in Hurricane, UT wants solar soon.'))->toBe('');
    expect(DB::table('bot_sessions')->count())->toBe(0);
});

it('handles STOP, START and HELP first, treats "cancel" as opting out, and uses "discard" to drop a draft', function () {
    ($this->msg)('My neighbour Maria Lopez in Hurricane, UT wants rooftop solar soon.');
    expect(($this->msg)('discard'))->toBe('Cancelled. Send new referral details any time.')
        ->and(DB::table('referral_drafts')->value('status'))->toBe('discarded');
    expect(($this->msg)('HELP'))->toContain('Reply STOP');
    ($this->msg)('cancel', '+14355550111'); // a carrier opt-out word, even from a member
    expect(DB::table('sms_opt_outs')->where('phone', '+14355550111')->exists())->toBeTrue();
    ($this->msg)('START', '+14355550111');
    expect(DB::table('sms_opt_outs')->where('phone', '+14355550111')->exists())->toBeFalse();
});

it('throttles each phone, ends quiet sessions after 30 minutes, and deletes conversations after 14 days', function () {
    ($this->msg)('My neighbour Maria Lopez in Hurricane, UT wants rooftop solar soon.');
    $this->travel(31)->minutes();
    expect(($this->msg)('Hi'))->toContain('Hi Pat!')->and(DB::table('bot_sessions')->where('state', 'cancelled')->count())->toBe(1);
    foreach (range(1, Bot::THROTTLE) as $i) {
        ($this->msg)('Hi');
    }
    expect(($this->msg)('Hi'))->toBe('');
    $this->travel(15)->days();
    IntakeEmails::purge();
    expect(DB::table('bot_messages')->count())->toBe(0);
});
