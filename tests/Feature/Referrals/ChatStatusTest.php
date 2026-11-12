<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Deliveries\SmsOptOut;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Referrals\Referrals;
use App\Support\Scanning\VirusScanner;
use App\Support\Sms\WhatsAppSender;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    modulesOn('chat_bot', 'ai_intake');
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    $this->sms = fakeSms();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $this->wa = new class implements WhatsAppSender
    {
        public array $sent = [];

        public function send(string $toE164, ?string $body, ?string $templateSid = null, array $variables = []): ?string
        {
            $this->sent[] = compact('toE164', 'body', 'templateSid', 'variables');

            return null;
        }
    };
    app()->instance(WhatsAppSender::class, $this->wa);
    app()->instance(VirusScanner::class, new class implements VirusScanner
    {
        public function scan(string $absolutePath): string
        {
            return 'clean';
        }
    });
    $s = app(Settings::class);
    $s->set('sms.twilio.token', 'twilio-secret', secret: true);
    $s->set('sms.twilio.sid', 'AC123');
    foreach (['ai' => 'anthropic', 'ai_fallback' => 'openai'] as $ch => $drv) {
        $s->set("{$ch}.driver", $drv);
        $s->set("{$ch}.{$drv}.api_key", 'k', secret: true);
        foreach (['model' => 'm', 'input_price' => '1', 'output_price' => '5', 'monthly_cap' => '100'] as $k => $v) {
            $s->set("{$ch}.{$drv}.{$k}", $v);
        }
    }
    $this->jpeg = "\xFF\xD8\xFF\xE1".pack('n', 20)."Exif\x00\x00GPS=37.1;xx\xFF\xDA\x00\x08IMAGEDATA\xFF\xD9";
    Http::fake([
        'api.twilio.com/*/Media/voice' => Http::response('OGG-AUDIO-BYTES'),
        'api.twilio.com/*/Media/card' => Http::response($this->jpeg),
        'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'New lead Maria Lopez in Hurricane Utah wants solar call 435 555 0142']),
        'api.anthropic.com/*' => function ($req) {
            $c = $req->data()['messages'][0]['content'];

            return Http::response(['content' => [['type' => 'text', 'text' => is_array($c) ? "Maria Lopez\n(435) 555-0142" : json_encode(['client_name' => 'Maria Lopez', 'notes' => 'Wants solar.', 'city' => 'Hurricane, UT', 'network' => null])]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
        },
    ]);
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $sv = app(Seats::class);
    $this->a = Team::forLeader(User::factory()->create(['phone' => '+14355550111', 'phone_verified_at' => now()]));
    $sv->approve($sv->apply($this->g, $this->a, $this->cities[2], 'L', null), null);
    $this->b = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Hurricane Sun']);
    $sv->approve($sv->apply($this->g, $this->b, $this->cities[1], 'L', null), null);
    $this->n = 0;
    $this->accepted = function () {
        $ans = collect(app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'])
            ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
                'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
            }])->all();
        $this->n++;
        $r = app(Referrals::class)->send($this->a->leader, $this->g, $this->cities[1], [...$ans, 'client_name' => 'Maria Lopez', 'client_phone' => '+143555503'.str_pad((string) $this->n, 2, '0', STR_PAD_LEFT)], true, null, true)['referral'];
        app(Referrals::class)->accept($r->fresh(), $this->b->leader);
    };
    $this->msg = function (array $params) {
        $params += ['To' => 'whatsapp:+14355550000'];
        $url = url('/webhooks/twilio/inbound');
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }

        return html_entity_decode(strip_tags((string) $this->withHeaders(['X-Twilio-Signature' => base64_encode(hash_hmac('sha1', $data, 'twilio-secret', true))])->post('/webhooks/twilio/inbound', $params)->assertOk()->getContent()));
    };
});

it('[5][9] sends an SMS status reply with the short name only, and logs it', function () {
    $this->a->leader->forceFill(['chat_channel' => 'sms'])->save();
    $before = count($this->sms->sent);
    ($this->accepted)();
    $sent = collect($this->sms->sent)->slice($before)->firstWhere('to', '+14355550111');
    expect($sent['message'])->toContain('Your referral for Maria L. in Hurricane, UT was accepted by Hurricane Sun')->not->toContain('Lopez')->not->toContain('555-03')
        ->and(DB::table('chat_deliveries')->where(['outcome' => 'sent', 'channel' => 'sms', 'type' => 'referral.accepted'])->count())->toBe(1);
});

it('[10] uses free text on WhatsApp within 24 hours, the approved template outside it, and skips without one', function () {
    $this->a->leader->forceFill(['chat_channel' => 'whatsapp'])->save();
    DB::table('bot_sessions')->insert(['user_id' => $this->a->leader_id, 'channel' => 'whatsapp', 'phone' => '+14355550111', 'state' => 'done', 'last_inbound_at' => now()->subHours(2), 'created_at' => now(), 'updated_at' => now()]);
    ($this->accepted)();
    expect($this->wa->sent[0]['body'])->toContain('was accepted by Hurricane Sun')->and($this->wa->sent[0]['templateSid'])->toBeNull();
    $this->travel(25)->hours();
    ($this->accepted)();
    expect(count($this->wa->sent))->toBe(1)->and(DB::table('chat_deliveries')->latest('id')->value('reason'))->toBe('No approved WhatsApp template');
    app(Settings::class)->set('sms.twilio.template_accepted', 'HX123');
    ($this->accepted)();
    expect($this->wa->sent[1])->toMatchArray(['templateSid' => 'HX123', 'body' => null, 'variables' => ['1' => 'Maria L.', '2' => 'Hurricane, UT', '3' => 'Hurricane Sun']]);
});

it('[12] lets members choose WhatsApp, SMS or off, only with a verified phone', function () {
    $this->actingAs($this->a->leader)->put('/app/settings/notifications', ['matrix' => [], 'chat_channel' => 'whatsapp', 'digest' => false]);
    expect($this->a->leader->fresh()->chat_channel)->toBe('whatsapp');
    $this->get('/app/settings/notifications')->assertInertia(fn ($p) => $p->where('chat.channel', 'whatsapp')->where('chat.phoneVerified', true));
    $this->b->leader->forceFill(['phone' => '+14355550199', 'phone_verified_at' => null])->save();
    $this->actingAs($this->b->leader)->put('/app/settings/notifications', ['matrix' => [], 'chat_channel' => 'sms', 'digest' => false]);
    expect($this->b->leader->fresh()->chat_channel)->toBe('none');
    $this->a->leader->forceFill(['chat_channel' => 'none'])->save();
    ($this->accepted)();
    expect($this->wa->sent)->toBe([])->and(DB::table('chat_deliveries')->count())->toBe(0);
});

it('[11] pauses status replies at the monthly cap and honours opt-outs', function () {
    $this->a->leader->forceFill(['chat_channel' => 'sms'])->save();
    app(Settings::class)->set('sms.twilio.monthly_cap', '0.01');
    ($this->accepted)();
    ($this->accepted)();
    expect(DB::table('chat_deliveries')->pluck('outcome')->all())->toBe(['sent', 'skipped'])->and(DB::table('chat_deliveries')->latest('id')->value('reason'))->toBe('Monthly messaging cap reached');
    app(Settings::class)->set('sms.twilio.monthly_cap', '0');
    SmsOptOut::query()->create(['phone' => '+14355550111', 'opted_out_at' => now()]);
    ($this->accepted)();
    expect(DB::table('chat_deliveries')->latest('id')->value('reason'))->toBe('Opted out');
});

it('[3] turns a WhatsApp voice note or card photo into a draft, echoing what it heard, and only fetches Twilio media', function () {
    $reply = ($this->msg)(['From' => 'whatsapp:+14355550111', 'Body' => '', 'NumMedia' => '1', 'MediaUrl0' => 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages/MM1/Media/voice', 'MediaContentType0' => 'audio/ogg']);
    expect($reply)->toContain('I heard: “New lead Maria Lopez')->and(DB::table('referral_drafts')->value('client_name'))->toBe('Maria Lopez')
        ->and(-(int) DB::table('ai_credit_ledger')->where('kind', 'usage')->sum('credits'))->toBe(2);
    DB::table('bot_sessions')->update(['state' => 'cancelled']);
    ($this->msg)(['From' => 'whatsapp:+14355550111', 'Body' => '', 'NumMedia' => '1', 'MediaUrl0' => 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages/MM2/Media/card', 'MediaContentType0' => 'image/jpeg']);
    expect(DB::table('referral_drafts')->count())->toBe(2)->and(DB::table('referral_drafts')->latest('id')->value('client_phone'))->toBe('(435) 555-0142');
    $reply = ($this->msg)(['From' => 'whatsapp:+14355550111', 'Body' => '', 'NumMedia' => '1', 'MediaUrl0' => 'https://evil.example.com/x.jpg', 'MediaContentType0' => 'image/jpeg']);
    expect(DB::table('referral_drafts')->count())->toBe(2);
});
