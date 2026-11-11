<?php

use App\Domains\Community\Events\EventInvite;
use App\Domains\Community\Events\Events;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Support\TimeZones;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $s = app(Seats::class);
    $mk = fn (string $n, int $c) => tap(tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]), fn ($t) => $s->approve($s->apply($this->g, $t, $this->cities[$c], 'L', null), null));
    [$this->a, $this->b, $this->c] = [$mk('Red Rock Solar', 0), $mk('Hurricane Sun', 1), $mk('Canyon Power', 2)];
    $this->owner = User::factory()->create(['timezone' => 'America/Denver']);
    $this->g->owners()->attach($this->owner->id);
    $this->a->leader->forceFill(['timezone' => 'America/New_York', 'timezone_chosen' => true])->save();
    $this->b->leader->forceFill(['timezone' => 'America/Los_Angeles', 'timezone_chosen' => true])->save();
    $this->make = function (array $over = []) {
        $this->actingAs($this->owner)->post('/app/events/solar-installers-network', [...['title' => 'Monthly meetup', 'kind' => 'virtual', 'video_url' => 'https://meet.example.com/abc',
            'starts_at' => now()->addDays(10)->setTime(18, 0)->format('Y-m-d\TH:i'), 'minutes' => 60, 'timezone' => 'America/Denver'], ...$over]);

        return (int) DB::table('events')->latest('id')->value('id');
    };
});

it('[1][14] creates events with a time zone, shown in each viewer’s own zone with the event’s alongside', function () {
    $this->actingAs($this->a->leader)->post('/app/events/solar-installers-network', ['title' => 'x', 'kind' => 'virtual', 'video_url' => 'https://x.example', 'starts_at' => now()->addDay()->format('Y-m-d\TH:i'), 'minutes' => 60, 'timezone' => 'America/Denver'])->assertForbidden();
    $id = ($this->make)();
    $e = DB::table('events')->find($id);
    expect(substr((string) $e->starts_at, 11, 5))->toBe(now()->setTimezone('America/Denver')->addDays(10)->setTime(18, 0)->utc()->format('H:i'))->and($e->timezone)->toBe('America/Denver');
    $this->actingAs($this->a->leader)->get("/app/events/{$id}")->assertInertia(fn ($p) => $p->component('app/Event')->where('event.differentZone', true)
        ->where('event.whenLocal', fn ($w) => str_contains((string) $w, '8:00 PM E'))->where('event.whenEvent', fn ($w) => str_contains((string) $w, '6:00 PM M')));
    $this->actingAs($this->owner)->post('/app/events/solar-installers-network', ['kind' => 'virtual'])->assertSessionHasErrors(['title', 'video_url', 'starts_at']);
});

it('[2][4] takes RSVPs and emails "going" a calendar invite in UTC that every calendar converts', function () {
    $id = ($this->make)();
    $this->actingAs($this->a->leader)->post("/app/events/{$id}/rsvp", ['status' => 'going'])->assertSessionHas('status', 'rsvp-going');
    $this->actingAs($this->b->leader)->post("/app/events/{$id}/rsvp", ['status' => 'maybe']);
    Notification::assertSentTo($this->a->leader, EventInvite::class, fn ($n) => str_contains($n->ics, 'METHOD:REQUEST') && str_contains($n->ics, 'UID:event-'.$id.'@') && preg_match('/DTSTART:\d{8}T\d{6}Z/', $n->ics) === 1 && str_contains($n->line, 'PM E'));
    Notification::assertNotSentTo($this->b->leader, EventInvite::class);
    $ics = $this->actingAs($this->a->leader)->get("/app/events/{$id}.ics")->assertOk()->assertHeader('content-type', 'text/calendar; charset=utf-8')->getContent();
    expect((string) $ics)->toContain('BEGIN:VEVENT')->toContain('SEQUENCE:0');
});

it('[3] reminds going members a day and an hour before, once each, in their own time zone', function () {
    $id = ($this->make)();
    $this->actingAs($this->a->leader)->post("/app/events/{$id}/rsvp", ['status' => 'going']);
    $this->actingAs($this->b->leader)->post("/app/events/{$id}/rsvp", ['status' => 'going']);
    $start = DB::table('events')->where('id', $id)->value('starts_at');
    $this->travelTo(now()->parse((string) $start, 'UTC')->subHours(24));
    app(Events::class)->reminders();
    app(Events::class)->reminders();
    $this->travelTo(now()->parse((string) $start, 'UTC')->subHour());
    app(Events::class)->reminders();
    $mine = fn (User $u) => Notification::sent($u, InAppNotification::class)->filter(fn ($n) => $n->type === 'event.reminder');
    expect($mine($this->a->leader))->toHaveCount(2)->and($mine($this->a->leader)->first()->body)->toContain('8:00 PM E')
        ->and($mine($this->b->leader)->first()->body)->toContain('5:00 PM P');
});

it('[5][12] lists attendees and records who actually came by QR check-in', function () {
    $id = ($this->make)(['kind' => 'in_person', 'location' => 'Hurricane Sun office, Moab', 'video_url' => null]);
    $code = (string) DB::table('events')->where('id', $id)->value('check_in_code');
    $this->actingAs($this->a->leader)->post("/app/events/{$id}/rsvp", ['status' => 'going']);
    $this->actingAs($this->a->leader)->get("/app/events/{$id}/check-in/{$code}")->assertStatus(422); // opens an hour before
    $this->travelTo(now()->parse((string) DB::table('events')->where('id', $id)->value('starts_at'), 'UTC')->addMinutes(5));
    $this->get("/app/events/{$id}/check-in/wrongcode123")->assertNotFound();
    $this->get("/app/events/{$id}/check-in/{$code}")->assertRedirect("/app/events/{$id}");
    $this->actingAs($this->c->leader)->get("/app/events/{$id}/check-in/{$code}"); // walked in without an RSVP
    $this->actingAs($this->owner)->get("/app/events/{$id}")->assertInertia(fn ($p) => $p->where('checkInUrl', url("/app/events/{$id}/check-in/{$code}"))
        ->where('attendees', fn ($a) => collect($a)->where('checkedIn', true)->count() === 2));
    $this->get("/app/events/{$id}/qr.svg")->assertOk()->assertHeader('content-type', 'image/svg+xml');
    $this->actingAs($this->a->leader)->get("/app/events/{$id}/qr.svg")->assertForbidden();
});

it('[6][13] keeps post-event notes and shows each attendee who they met, to follow up', function () {
    $id = ($this->make)(['kind' => 'in_person', 'location' => 'Moab', 'video_url' => null]);
    $code = (string) DB::table('events')->where('id', $id)->value('check_in_code');
    $this->travelTo(now()->parse((string) DB::table('events')->where('id', $id)->value('starts_at'), 'UTC')->addMinutes(5));
    foreach ([$this->a, $this->b] as $t) {
        $this->actingAs($t->leader)->get("/app/events/{$id}/check-in/{$code}");
    }
    $this->actingAs($this->c->leader)->post("/app/events/{$id}/rsvp", ['status' => 'going']); // said going, didn't come
    $this->travel(2)->hours();
    $this->actingAs($this->owner)->post("/app/events/{$id}/notes", ['notes' => 'Slides at the link. Next one is March 3. Call me on 435-555-0100'])->assertSessionHas('status', 'event-notes');
    $this->actingAs($this->a->leader)->get("/app/events/{$id}")->assertInertia(fn ($p) => $p->where('event.notes', fn ($n) => str_contains((string) $n, 'Next one is March 3') && ! str_contains((string) $n, '555-0100'))
        ->where('met', [['team' => 'Hurricane Sun', 'profile' => '/t/'.$this->b->fresh()->slug]]));
    $this->actingAs($this->c->leader)->get("/app/events/{$id}")->assertInertia(fn ($p) => $p->where('met', []));
});

it('[7] sends a revised invite on changes and a cancellation that removes it from calendars', function () {
    $id = ($this->make)();
    $this->actingAs($this->a->leader)->post("/app/events/{$id}/rsvp", ['status' => 'going']);
    $this->actingAs($this->owner)->post("/app/events/{$id}/update", ['title' => 'Monthly meetup (new time)', 'starts_at' => now()->addDays(11)->setTime(19, 0)->format('Y-m-d\TH:i')])->assertSessionHas('status', 'event-updated');
    Notification::assertSentTo($this->a->leader, EventInvite::class, fn ($n) => str_contains($n->ics, 'SEQUENCE:1') && str_starts_with($n->subject, 'Updated:'));
    $this->post("/app/events/{$id}/cancel")->assertSessionHas('status', 'event-cancelled');
    Notification::assertSentTo($this->a->leader, EventInvite::class, fn ($n) => str_contains($n->ics, 'METHOD:CANCEL') && str_contains($n->ics, 'SEQUENCE:2') && $n->cancel);
    $this->actingAs($this->b->leader)->post("/app/events/{$id}/rsvp", ['status' => 'going'])->assertStatus(422);
});

it('[8] shows the video link only to people going, from 15 minutes before the start', function () {
    $id = ($this->make)();
    $this->actingAs($this->a->leader)->post("/app/events/{$id}/rsvp", ['status' => 'going']);
    $start = now()->parse((string) DB::table('events')->where('id', $id)->value('starts_at'), 'UTC');
    $this->travelTo($start->copy()->subMinutes(30));
    $this->get("/app/events/{$id}")->assertInertia(fn ($p) => $p->where('event.videoUrl', null));
    $this->travelTo($start->copy()->subMinutes(10));
    $this->get("/app/events/{$id}")->assertInertia(fn ($p) => $p->where('event.videoUrl', 'https://meet.example.com/abc'));
    $this->actingAs($this->b->leader)->get("/app/events/{$id}")->assertInertia(fn ($p) => $p->where('event.videoUrl', null)); // not going
});

it('[9] fills to capacity, waitlists the rest, and gives a freed place to the next person', function () {
    $id = ($this->make)(['kind' => 'in_person', 'location' => 'Moab', 'video_url' => null, 'capacity' => 1]);
    $this->actingAs($this->a->leader)->post("/app/events/{$id}/rsvp", ['status' => 'going']);
    $this->actingAs($this->b->leader)->post("/app/events/{$id}/rsvp", ['status' => 'going'])->assertSessionHas('status', 'rsvp-waitlist');
    $this->actingAs($this->c->leader)->post("/app/events/{$id}/rsvp", ['status' => 'going']);
    $this->actingAs($this->a->leader)->post("/app/events/{$id}/rsvp", ['status' => 'no']);
    expect(DB::table('event_rsvps')->where(['event_id' => $id, 'user_id' => $this->b->leader_id])->value('status'))->toBe('going')
        ->and(DB::table('event_rsvps')->where(['event_id' => $id, 'user_id' => $this->c->leader_id])->value('status'))->toBe('waitlist');
    Notification::assertSentTo($this->b->leader, EventInvite::class, fn ($n) => str_starts_with($n->subject, 'A place opened up'));
});

it('[10] creates recurring dates, each with its own RSVPs, keeping "first Tuesday" a first Tuesday', function () {
    $firstTuesday = now()->addMonthNoOverflow()->startOfMonth();
    $firstTuesday = $firstTuesday->isTuesday() ? $firstTuesday : $firstTuesday->next('Tuesday');
    $this->actingAs($this->owner)->post('/app/events/solar-installers-network', ['title' => 'First Tuesday breakfast', 'kind' => 'in_person', 'location' => 'Moab',
        'starts_at' => $firstTuesday->setTime(8, 0)->format('Y-m-d\TH:i'), 'minutes' => 90, 'timezone' => 'America/Denver', 'repeat' => 'monthly', 'count' => 4]);
    $dates = DB::table('events')->orderBy('id')->pluck('starts_at')->map(fn ($d) => now()->parse((string) $d, 'UTC')->setTimezone('America/Denver'));
    expect($dates)->toHaveCount(4)->and($dates->every(fn ($d) => $d->isTuesday() && $d->day <= 7 && $d->format('H:i') === '08:00'))->toBeTrue()
        ->and(DB::table('events')->whereNotNull('series_id')->count())->toBe(3);
});

it('[11] posts each new event to the feed with one-tap RSVP', function () {
    $id = ($this->make)();
    $this->actingAs($this->a->leader)->get('/app/feed/solar-installers-network')->assertInertia(fn ($p) => $p->where('posts.0.kind', 'event')->where('posts.0.eventId', $id));
});

it('defaults members’ time zone to their team’s city, never overriding their own choice, and lets them choose', function () {
    expect(TimeZones::forPlace('TX', 31.76, -106.49))->toBe('America/Denver')->and(TimeZones::forPlace('TX', 32.78, -96.80))->toBe('America/Chicago')
        ->and(TimeZones::forPlace('FL', 30.42, -87.22))->toBe('America/Chicago')->and(TimeZones::forPlace('AZ', 33.45, -112.07))->toBe('America/Phoenix')
        ->and(TimeZones::forPlace('TN', 35.96, -83.92))->toBe('America/New_York');
    $city = DB::table('cities')->join('states', 'states.id', '=', 'cities.state_id')->where('cities.id', $this->cities[0]->id)->first(['states.code', 'cities.lat', 'cities.lon']);
    $u = User::factory()->create(['timezone' => 'America/Denver']);
    $t = Team::forLeader($u);
    app(Seats::class)->approve(app(Seats::class)->apply($this->g, $t, $this->cities[3], 'L', null), null);
    $c3 = DB::table('cities')->join('states', 'states.id', '=', 'cities.state_id')->where('cities.id', $this->cities[3]->id)->first(['states.code', 'cities.lat', 'cities.lon']);
    expect($u->fresh()->timezone)->toBe(TimeZones::forPlace((string) $c3->code, (float) $c3->lat, (float) $c3->lon));
    $this->actingAs($u)->put('/app/settings/notifications', ['matrix' => [], 'timezone' => 'Pacific/Honolulu', 'digest' => false]);
    expect($u->fresh()->timezone)->toBe('Pacific/Honolulu')->and((bool) $u->fresh()->timezone_chosen)->toBeTrue();
    TimeZones::applyCityDefault([$u->id], $this->cities[0]->id);
    expect($u->fresh()->timezone)->toBe('Pacific/Honolulu');
});
