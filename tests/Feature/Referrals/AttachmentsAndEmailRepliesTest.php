<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Mail\PlatformMessage;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\InboundReplies;
use App\Domains\Referrals\Referrals;
use App\Domains\Referrals\ReplyAddress;
use App\Support\Scanning\ClamdScanner;
use App\Support\Scanning\VirusScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    Storage::fake('private');
    fakeSms();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $this->scan = 'clean';
    app()->instance(VirusScanner::class, new class($this) implements VirusScanner
    {
        public function __construct(private $t) {}

        public function scan(string $absolutePath): string
        {
            return $this->t->scan;
        }
    });
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $cities = City::where('is_enabled', true)->orderBy('id')->get();
    $s = app(Seats::class);
    $mk = fn (string $n, string $e) => tap(Team::forLeader(User::factory()->create(['email' => $e])))->update(['name' => $n]);
    [$this->a, $this->b] = [$mk('Red Rock Solar', 'jordan@redrock.example'), $mk('Hurricane Sun', 'chris@hurricane.example')];
    $s->approve($s->apply($g, $this->a, $cities[0], 'L', null), null);
    $s->approve($s->apply($g, $this->b, $cities[1], 'L', null), null);
    $ans = [];
    foreach (app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'] as $f) {
        $ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }
    $this->r = app(Referrals::class)->send($this->a->leader, $g, $cities[1], [...$ans, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100'], true, null)['referral'];
    $this->url = "/app/referrals/{$this->r->ulid}";
});

$fileUrl = fn ($test, User $u) => $test->actingAs($u)->get($test->url)->inertiaProps('messages.0.files.0');

it('scans attachments, and lets the receiver open them only after accepting', function () use ($fileUrl) {
    $this->actingAs($this->a->leader)->post("{$this->url}/messages", ['body' => 'Site photos', 'files' => [UploadedFile::fake()->create('roof.jpg', 40, 'image/jpeg')]])->assertRedirect();
    $row = DB::table('referral_attachments')->first();
    expect($row->status)->toBe('clean')->and($row->original_name)->toBe('roof.jpg');
    Storage::disk('private')->assertExists($row->path);

    expect($fileUrl($this, $this->a->leader)['url'])->toContain('signature=');
    $locked = $fileUrl($this, $this->b->leader);
    expect($locked['url'])->toBeNull()->and($locked['name'])->toBe('roof.jpg');
    $signed = Illuminate\Support\Facades\URL::temporarySignedRoute('referrals.attachment', now()->addMinutes(5), ['referral' => $this->r->ulid, 'attachment' => $row->id]);
    $this->actingAs($this->b->leader)->get($signed)->assertForbidden();

    app(Referrals::class)->accept($this->r->fresh(), $this->b->leader);
    $res = $this->get($fileUrl($this, $this->b->leader)['url'])->assertOk();
    expect($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')->and($res->headers->get('Content-Disposition'))->toContain('attachment');
    $this->actingAs(Team::forLeader(User::factory()->create())->leader)->get($signed)->assertNotFound();
    $this->actingAs($this->a->leader)->get("{$this->url}/files/{$row->id}")->assertForbidden();
});

it('quarantines infected files and never serves them', function () use ($fileUrl) {
    $this->scan = 'infected';
    $this->actingAs($this->a->leader)->post("{$this->url}/messages", ['files' => [UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf')]]);
    $row = DB::table('referral_attachments')->first();
    expect($row->status)->toBe('infected')->and($row->path)->toStartWith('quarantine/')->and($fileUrl($this, $this->a->leader)['url'])->toBeNull();
    $signed = Illuminate\Support\Facades\URL::temporarySignedRoute('referrals.attachment', now()->addMinutes(5), ['referral' => $this->r->ulid, 'attachment' => $row->id]);
    $this->get($signed)->assertStatus(423);
});

it('holds unscanned files when scanning is required', function () use ($fileUrl) {
    $this->scan = 'unavailable';
    $this->actingAs($this->a->leader)->post("{$this->url}/messages", ['files' => [UploadedFile::fake()->create('notes.txt', 1, 'text/plain')]]);
    expect(DB::table('referral_attachments')->value('status'))->toBe('unscanned')->and($fileUrl($this, $this->a->leader)['url'])->toBeNull();
    config(['attachments.require_scan' => false]);
    expect($fileUrl($this, $this->a->leader)['url'])->not->toBeNull();
});

it('refuses unsafe types and too many files', function () {
    $this->actingAs($this->a->leader)->post("{$this->url}/messages", ['files' => [UploadedFile::fake()->create('run.exe', 5)]])->assertSessionHasErrors('files.0');
    $this->post("{$this->url}/messages", ['files' => array_map(fn ($i) => UploadedFile::fake()->create("p{$i}.jpg", 10, 'image/jpeg'), range(1, 4))])->assertSessionHasErrors('files');
    $this->post("{$this->url}/messages", [])->assertSessionHasErrors('body');
});

it('speaks the ClamAV network protocol', function () {
    $port = 38000 + random_int(0, 999);
    $server = proc_open([PHP_BINARY, '-r', '$s=stream_socket_server("tcp://127.0.0.1:'.$port.'");$c=stream_socket_accept($s,5);$cmd=fread($c,10);$got="";while(true){$l=unpack("N",fread($c,4))[1];if($l===0)break;$got.=fread($c,$l);}fwrite($c,str_contains($got,"EICAR")?"stream: Eicar-Test-Signature FOUND\0":"stream: OK\0");fclose($c);'], [], $pipes);
    usleep(300000);
    $path = tempnam(sys_get_temp_dir(), 'eicar');
    file_put_contents($path, 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
    expect((new ClamdScanner('127.0.0.1', $port))->scan($path))->toBe('infected');
    proc_close($server);
    expect((new ClamdScanner('127.0.0.1', 1))->scan($path))->toBe('unavailable');
});

it('gives each person a signed reply address that can’t be forged', function () {
    config(['mail.inbound.domain' => 'reply.t2t.example']);
    $addr = ReplyAddress::for($this->r, $this->b->leader);
    expect(strlen(explode('@', $addr)[0]))->toBeLessThan(64)->and(ReplyAddress::parse($addr))->toBe(['referral' => $this->r->id, 'user' => $this->b->leader->id])
        ->and(ReplyAddress::parse(preg_replace('/\.([0-9a-f]{12})@/', '.000000000000@', $addr)))->toBeNull();
});

it('adds Reply-To to a single message email, and a reply link inside bundles', function () {
    config(['mail.inbound.domain' => 'reply.t2t.example']);
    $this->actingAs($this->a->leader)->post("{$this->url}/messages", ['body' => 'Hello']);
    $item = DB::table('notification_outbox')->where(['user_id' => $this->b->leader->id, 'type' => 'referral.message'])->first();
    expect($item->reply_to)->toStartWith('reply+');
    $one = (new PlatformMessage('referral.message', [['title' => 'T', 'body' => 'B', 'url' => null, 'reply_to' => $item->reply_to]]))->toMail($this->b->leader);
    expect($one->replyTo[0][0])->toBe($item->reply_to);
    $many = (new PlatformMessage('referral.message', [['title' => 'T', 'body' => 'B', 'url' => null, 'reply_to' => $item->reply_to], ['title' => 'U', 'body' => 'C', 'url' => null]]))->toMail($this->b->leader);
    expect($many->replyTo)->toBe([])->and(implode(' ', $many->introLines))->toContain('mailto:'.$item->reply_to);
});

it('posts email replies from the member’s own address, stripped of quotes, once', function () {
    config(['mail.inbound.domain' => 'reply.t2t.example', 'mail.inbound.secret' => 'hook-secret']);
    $to = ReplyAddress::for($this->r, $this->b->leader);
    $payload = ['OriginalRecipient' => $to, 'FromFull' => ['Email' => 'Chris@Hurricane.example'], 'TextBody' => "Booked for Friday 10am.\n\nOn Tue, Nov 10, Jordan wrote:\n> Any update?", 'MessageID' => 'm-1'];

    $this->postJson('/webhooks/inbound-email', $payload)->assertUnauthorized();
    $this->withHeader('X-Inbound-Secret', 'hook-secret')->postJson('/webhooks/inbound-email', $payload)->assertOk()->assertJson(['status' => 'posted']);
    expect(DB::table('referral_messages')->where('user_id', $this->b->leader->id)->value('body'))->toBe('Booked for Friday 10am.');
    $this->withHeader('X-Inbound-Secret', 'hook-secret')->postJson('/webhooks/inbound-email', $payload)->assertJson(['status' => 'duplicate']);
    $this->withHeader('X-Inbound-Secret', 'hook-secret')->postJson('/webhooks/inbound-email', [...$payload, 'FromFull' => ['Email' => 'spoof@evil.example'], 'MessageID' => 'm-2'])->assertJson(['status' => 'wrong_sender']);
    $this->withHeader('X-Inbound-Secret', 'hook-secret')->postJson('/webhooks/inbound-email', [...$payload, 'OriginalRecipient' => 'reply+1.1.000000000000@reply.t2t.example', 'MessageID' => 'm-3'])->assertJson(['status' => 'bad_address']);
    expect(DB::table('referral_messages')->count())->toBe(1);
    expect(InboundReplies::strip("Sounds good\nSent from my iPhone"))->toBe('Sounds good');
});
