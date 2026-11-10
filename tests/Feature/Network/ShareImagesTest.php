<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Prelaunch\ConfirmReservationNotification;
use App\Domains\Network\Prelaunch\GenerateShareImages;
use App\Domains\Network\Prelaunch\ReservationThanksNotification;
use App\Domains\Network\Prelaunch\ShareImage;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    Storage::fake('public');
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $this->city = City::where('is_enabled', true)->orderBy('id')->firstOrFail();
});

function confirmedShareId($test): string
{
    $test->post('/reserve', ['industry' => 'solar', 'city_id' => $test->city->id, 'name' => 'Ana Ruiz', 'business' => 'SunPath <Solar> & Co', 'email' => 'ana@example.com']);
    $token = basename((string) Notification::sent(new AnonymousNotifiable, ConfirmReservationNotification::class)->last()->url);
    $test->get("/reserve/confirm/{$token}");

    return (string) DB::table('reservations')->value('share_id');
}

it('escapes names in the card design', function () {
    expect(ShareImage::svg('og', 'A <b>&</b> Co', 'Houston, TX', 'Solar'))->toContain('A &lt;b&gt;&amp;&lt;/b&gt; Co')->not->toContain('<b>');
});

it('queues card rendering when a reservation is confirmed', function () {
    Queue::fake();
    $this->post('/reserve', ['industry' => 'solar', 'city_id' => $this->city->id, 'name' => 'Ana', 'email' => 'ana@example.com']);
    $token = basename((string) Notification::sent(new AnonymousNotifiable, ConfirmReservationNotification::class)->last()->url);
    $this->get("/reserve/confirm/{$token}");
    Queue::assertPushed(GenerateShareImages::class);
});

it('renders branded PNG cards in three sizes and emails the card with the share link', function () {
    if (! ShareImage::available()) {
        $this->markTestSkipped('librsvg is not installed here (it is in CI and production).');
    }
    $id = confirmedShareId($this);
    foreach (ShareImage::SIZES as $size => [$w, $h]) {
        Storage::disk('public')->assertExists(ShareImage::path($id, $size));
        $info = getimagesizefromstring((string) Storage::disk('public')->get(ShareImage::path($id, $size)));
        expect([$info[0], $info[1], $info['mime']])->toBe([$w, $h, 'image/png']);
    }
    Notification::assertSentTo(new AnonymousNotifiable, ReservationThanksNotification::class, fn ($n) => $n->cardPath !== null && str_ends_with($n->shareUrl, "/reserve/c/{$id}"));
    $this->get("/reserve/c/{$id}/og.png")->assertOk()->assertHeader('content-type', 'image/png');
});

it('points link previews at the personal card and 404s unknown cards', function () {
    $id = confirmedShareId($this);
    $this->get("/reserve/c/{$id}")->assertInertia(fn ($p) => $p->where('seo.image', url("/reserve/c/{$id}/og.png"))->where('caption', fn ($c) => str_contains($c, 'founding member')));
    $this->get('/reserve/c/'.str_repeat('Z', 26).'/og.png')->assertNotFound();
});
