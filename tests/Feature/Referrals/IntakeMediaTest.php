<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Ai\AiCredits;
use App\Domains\Platform\Settings\Settings;
use App\Support\ImageMetadata;
use App\Support\Scanning\VirusScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    modulesOn('ai_intake');
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    fakeSms();
    Storage::fake('local');
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $s = app(Settings::class);
    foreach (['ai' => 'anthropic', 'ai_fallback' => 'openai'] as $ch => $drv) {
        $s->set("{$ch}.driver", $drv);
        $s->set("{$ch}.{$drv}.api_key", 'k-'.$drv, secret: true);
        foreach (['model' => 'm', 'input_price' => '1', 'output_price' => '5', 'monthly_cap' => '100'] as $k => $v) {
            $s->set("{$ch}.{$drv}.{$k}", $v);
        }
    }
    $this->verdict = 'clean';
    app()->instance(VirusScanner::class, new class($this) implements VirusScanner
    {
        public function __construct(private $t) {}

        public function scan(string $absolutePath): string
        {
            return $this->t->verdict;
        }
    });
    $this->calls = [];
    Http::fake([
        'api.openai.com/v1/audio/transcriptions' => function () {
            $this->calls[] = 'transcribe';

            return Http::response(['text' => 'New lead Maria Lopez in Hurricane Utah wants solar soon call her on 435 555 0142']);
        },
        'api.anthropic.com/*' => function ($req) {
            $content = $req->data()['messages'][0]['content'];
            if (is_array($content)) { // the photo
                $this->calls[] = ['image', base64_decode($content[0]['source']['data']), $content[0]['source']['media_type']];

                return Http::response(['content' => [['type' => 'text', 'text' => "Maria Lopez\nSunrise Roofing\n(435) 555-0142\nmaria@roof.example"]], 'usage' => ['input_tokens' => 900, 'output_tokens' => 20]]);
            }
            $this->calls[] = 'extract';

            return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['client_name' => 'Maria Lopez', 'notes' => 'Wants solar soon.', 'city' => 'Hurricane, UT', 'network' => null])]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 30]]);
        },
    ]);
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $sv = app(Seats::class);
    $this->a = Team::forLeader(User::factory()->create());
    $sv->approve($sv->apply($this->g, $this->a, $this->cities[2], 'L', null), null);
    // A JPEG with an EXIF block holding GPS coordinates, then the image data.
    $exif = "Exif\x00\x00GPSLatitude=37.17;GPSLongitude=-113.29";
    $this->jpeg = "\xFF\xD8".("\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00")."\xFF\xE1".pack('n', strlen($exif) + 2).$exif."\xFF\xDA\x00\x08IMAGEDATA\xFF\xD9";
});

it('[2] transcribes a voice note for the member to check, makes the draft from the edited text, and never keeps the audio', function () {
    $this->actingAs($this->a->leader)->post('/app/intake/voice', ['audio' => UploadedFile::fake()->create('note.webm', 200, 'audio/webm')])->assertRedirect()->assertSessionHas('status', 'voice-transcribed');
    $m = DB::table('intake_messages')->first();
    expect($m->channel)->toBe('voice')->and($m->body)->toContain('Maria Lopez')->and(Storage::disk('local')->allFiles())->toBe([]);
    $this->get('/app/drafts?transcript='.$m->id)->assertInertia(fn ($p) => $p->where('transcript.text', $m->body));
    $edited = 'New lead Maria Lopez in Hurricane, UT wants solar soon. Call her on (435) 555-0142.';
    $res = $this->post("/app/intake/{$m->id}/draft", ['text' => $edited]);
    $d = DB::table('referral_drafts')->first();
    $res->assertRedirect('/app/send?draft='.$d->ulid);
    expect($d->client_phone)->toBe('(435) 555-0142')->and($d->client_name)->toBe('Maria Lopez')->and(DB::table('intake_messages')->value('body'))->toBe($edited)
        ->and($this->calls)->toBe(['transcribe', 'extract'])
        ->and(-(int) DB::table('ai_credit_ledger')->where(['team_id' => $this->a->id, 'kind' => 'usage'])->sum('credits'))->toBe(2);
    $this->post("/app/intake/{$m->id}/draft", ['text' => $edited])->assertNotFound(); // once
});

it('[2] explains plainly when voice notes can’t be used, without spending credits', function () {
    app(Settings::class)->set('ai_fallback.driver', 'none');
    $this->actingAs($this->a->leader)->post('/app/intake/voice', ['audio' => UploadedFile::fake()->create('note.webm', 200, 'audio/webm')])->assertSessionHasErrors('audio');
    app(Settings::class)->set('ai_fallback.driver', 'openai');
    AiCredits::grantMonthly($this->a);
    DB::table('ai_credit_ledger')->insert(['team_id' => $this->a->id, 'kind' => 'adjust', 'credits' => -(AiCredits::balance($this->a) - 1), 'created_at' => now()]);
    $this->post('/app/intake/voice', ['audio' => UploadedFile::fake()->create('note.webm', 200, 'audio/webm')])->assertSessionHasErrors('credits');
    expect($this->calls)->toBe([])->and(AiCredits::balance($this->a))->toBe(1);
    $this->post('/app/intake/voice', ['audio' => UploadedFile::fake()->create('note.pdf', 20, 'application/pdf')])->assertSessionHasErrors('audio');
});

it('[3] reads a card photo with location data removed, takes contacts from the card’s text, and never keeps the photo', function () {
    $this->actingAs($this->a->leader)->post('/app/intake/photo', ['photo' => UploadedFile::fake()->createWithContent('card.jpg', $this->jpeg)])->assertRedirect();
    [$kind, $sentBytes, $mime] = $this->calls[0];
    expect($kind)->toBe('image')->and($mime)->toBe('image/jpeg')->and($sentBytes)->not->toContain('GPSLatitude')->toContain('IMAGEDATA')->toContain('JFIF')
        ->and($this->calls[1])->toBe('extract');
    $d = DB::table('referral_drafts')->first();
    expect($d->client_phone)->toBe('(435) 555-0142')->and($d->client_email)->toBe('maria@roof.example')->and((int) $d->city_id)->toBe($this->cities[1]->id)
        ->and(Storage::disk('local')->allFiles())->toBe([])->and(DB::table('intake_messages')->value('channel'))->toBe('photo');
});

it('[3] refuses infected or unscannable photos and non-images before any AI call', function () {
    $this->verdict = 'infected';
    $this->actingAs($this->a->leader)->post('/app/intake/photo', ['photo' => UploadedFile::fake()->createWithContent('card.jpg', $this->jpeg)])->assertSessionHasErrors('photo');
    $this->verdict = 'unavailable';
    config(['attachments.require_scan' => true]);
    $this->post('/app/intake/photo', ['photo' => UploadedFile::fake()->createWithContent('card.jpg', $this->jpeg)])->assertSessionHasErrors('photo');
    $this->verdict = 'clean';
    $this->post('/app/intake/photo', ['photo' => UploadedFile::fake()->createWithContent('card.jpg', '%PDF-1.7 pretending to be a photo')])->assertSessionHasErrors('photo');
    expect($this->calls)->toBe([])->and(Storage::disk('local')->allFiles())->toBe([])->and(DB::table('referral_drafts')->count())->toBe(0);
});

it('strips PNG and WebP metadata losslessly and copes with truncated files', function () {
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $png = "\x89PNG\r\n\x1A\n".$chunk('IHDR', str_repeat("\x00", 13)).$chunk('eXIf', 'GPS=37.1').$chunk('tEXt', "Comment\x00home").$chunk('IDAT', 'PIXELS').$chunk('IEND', '');
    $out = ImageMetadata::strip($png);
    expect($out)->not->toContain('GPS=37.1')->not->toContain('home')->toContain('PIXELS')->toContain('IEND');
    $riff = fn (string $t, string $d) => $t.pack('V', strlen($d)).$d.(strlen($d) % 2 ? "\x00" : '');
    $body = $riff('VP8X', "\x0C".str_repeat("\x00", 9)).$riff('VP8 ', 'FRAME').$riff('EXIF', 'GPS=37.1');
    $webp = ImageMetadata::strip('RIFF'.pack('V', 4 + strlen($body)).'WEBP'.$body);
    expect($webp)->not->toContain('GPS=37.1')->toContain('FRAME')->and(ord($webp[20]) & 0x0C)->toBe(0)->and(ImageMetadata::type($webp))->toBe('image/webp');
    expect(ImageMetadata::strip(substr($png, 0, 40)))->toBeString()->and(ImageMetadata::type('hello'))->toBeNull();
});
