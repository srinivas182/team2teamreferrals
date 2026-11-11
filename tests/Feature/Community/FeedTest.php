<?php

use App\Domains\Community\FeedChanged;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Referrals\Closing;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\Referrals;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
    $mk = fn (string $n) => tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
    [$this->a, $this->b, $this->c] = [$mk('Red Rock Solar'), $mk('Hurricane Sun'), $mk('Canyon Power')];
    foreach ([$this->a, $this->b, $this->c] as $i => $t) {
        $s->approve($s->apply($this->g, $t, $this->cities[$i], 'L', null), null);
    }
    $this->owner = User::factory()->create();
    $this->g->owners()->attach($this->owner->id);
    $this->url = '/app/feed/solar-installers-network';
    $this->postId = fn () => (int) DB::table('feed_posts')->latest('id')->value('id');
});

it('[1] lets members post, comment and react (tap again to undo); outsiders can’t', function () {
    $this->actingAs($this->a->leader)->post($this->url.'/posts', ['kind' => 'post', 'body' => 'Hello network!'])->assertSessionHas('status', 'posted');
    $id = ($this->postId)();
    $this->actingAs($this->b->leader)->post("/app/feed/posts/{$id}/comments", ['body' => 'Welcome!'])->assertRedirect();
    $this->post("/app/feed/posts/{$id}/react", ['kind' => 'celebrate']);
    $this->get($this->url)->assertInertia(fn ($p) => $p->component('app/Feed')->where('posts.0.body', 'Hello network!')->where('posts.0.reactions.celebrate', 1)
        ->where('posts.0.myReaction', 'celebrate')->where('posts.0.comments.0.team', 'Hurricane Sun'));
    $this->post("/app/feed/posts/{$id}/react", ['kind' => 'celebrate']);
    expect(DB::table('feed_reactions')->count())->toBe(0);
    $this->actingAs(User::factory()->create())->post($this->url.'/posts', ['kind' => 'post', 'body' => 'Spam'])->assertForbidden();
});

it('[2][3] lets only owners pin and announce; announcements reach every member at once and lead the feed', function () {
    $this->actingAs($this->a->leader)->post($this->url.'/posts', ['kind' => 'announcement', 'body' => 'Members cannot announce'])->assertForbidden();
    $this->post($this->url.'/posts', ['kind' => 'post', 'body' => 'Older post']);
    $older = ($this->postId)();
    $this->post("/app/feed/posts/{$older}/pin")->assertForbidden();
    $this->actingAs($this->owner)->post($this->url.'/posts', ['kind' => 'announcement', 'body' => 'Quarterly meetup on the 20th']);
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'network.announcement' && str_contains($n->body, 'Quarterly meetup'));
    $this->actingAs($this->a->leader)->post($this->url.'/posts', ['kind' => 'post', 'body' => 'Newest post']);
    $this->actingAs($this->owner)->post("/app/feed/posts/{$older}/pin");
    $this->get($this->url)->assertInertia(fn ($p) => $p->where('posts.0.pinned', true)->where('posts.1.pinned', true)->where('posts.2.body', 'Newest post'));
});

it('[4] hides an item after three reports (one per person) for the owner to restore or remove', function () {
    $this->actingAs($this->a->leader)->post($this->url.'/posts', ['kind' => 'post', 'body' => 'Questionable post']);
    $id = ($this->postId)();
    $this->actingAs($this->b->leader)->post("/app/feed/report/post/{$id}", ['reason' => 'Off topic']);
    $this->post("/app/feed/report/post/{$id}"); // the same person again doesn't count
    $this->actingAs($this->c->leader)->post("/app/feed/report/post/{$id}");
    expect((int) DB::table('feed_posts')->value('reports'))->toBe(2)->and(DB::table('feed_posts')->value('hidden_at'))->toBeNull();
    $this->actingAs($this->owner)->post("/app/feed/report/post/{$id}");
    expect(DB::table('feed_posts')->value('hidden_at'))->not->toBeNull();
    Notification::assertSentTo($this->owner, InAppNotification::class, fn ($n) => $n->type === 'feed.moderation');
    $this->actingAs($this->b->leader)->get($this->url)->assertInertia(fn ($p) => $p->has('posts', 0));

    $this->actingAs($this->a->leader)->get('/app/group/solar-installers-network/moderation')->assertForbidden();
    $this->actingAs($this->owner)->get('/app/group/solar-installers-network/moderation')->assertInertia(fn ($p) => $p->component('app/group/Moderation')->where('items.0.reports', 3)->where('items.0.reasons.0', 'Off topic'));
    $this->post("/app/feed/moderate/post/{$id}", ['restore' => true]);
    expect(DB::table('feed_posts')->value('hidden_at'))->toBeNull()->and(DB::table('feed_reports')->count())->toBe(0);
    $this->post("/app/feed/moderate/post/{$id}", ['restore' => false]);
    expect(DB::table('feed_posts')->value('removed_at'))->not->toBeNull();
});

it('[5][6] masks client phone numbers and emails and stars out blocked words, in posts and comments', function () {
    $this->actingAs($this->a->leader)->post($this->url.'/posts', ['kind' => 'post', 'body' => 'Call Tom on (435) 555-0100 or tom@example.com, this shit is urgent']);
    $body = (string) DB::table('feed_posts')->value('body');
    expect($body)->not->toContain('555-0100')->not->toContain('tom@example.com')->not->toContain('shit')->toContain('****');
    $this->post('/app/feed/posts/'.($this->postId)().'/comments', ['body' => 'Text 435-555-0199']);
    expect((string) DB::table('feed_comments')->value('body'))->not->toContain('555-0199');
    app(Settings::class)->set('feed.blocked_words', 'lemon');
    $this->post($this->url.'/posts', ['kind' => 'post', 'body' => 'This lemon deal']);
    expect((string) DB::table('feed_posts')->latest('id')->value('body'))->toBe('This ***** deal');
});

it('[7] turns an Ask into a referral: the team covering that city replies, and the asker sends in one tap', function () {
    $this->actingAs($this->a->leader)->post($this->url.'/posts', ['kind' => 'ask', 'body' => 'Anyone have a great installer here?'])->assertSessionHasErrors('city_id');
    $this->post($this->url.'/posts', ['kind' => 'ask', 'body' => 'Anyone have a great installer here?', 'city_id' => $this->cities[1]->id]);
    $id = ($this->postId)();
    $this->actingAs($this->c->leader)->post("/app/feed/posts/{$id}/comments", ['body' => 'Not my area, sorry']);
    $this->actingAs($this->b->leader)->post("/app/feed/posts/{$id}/comments", ['body' => 'That’s my city, happy to help']);
    Notification::assertSentTo($this->a->leader, InAppNotification::class, fn ($n) => $n->type === 'feed.reply' && str_contains($n->title, 'covers that city'));
    $send = '/app/send?group=solar-installers-network&city='.$this->cities[1]->id.'&from='.$this->b->fresh()->slug;
    $this->actingAs($this->a->leader)->get($this->url)->assertInertia(fn ($p) => $p->where('posts.0.city', fn ($c) => str_starts_with((string) $c, $this->cities[1]->name))
        ->where('posts.0.comments.0.offer', false)->where('posts.0.comments.0.sendUrl', null)->where('posts.0.comments.1.offer', true)->where('posts.0.comments.1.sendUrl', $send));
    $this->get($send)->assertInertia(fn ($p) => $p->where('prefill.cityId', $this->cities[1]->id)->where('prefill.fromProfile', $this->b->fresh()->slug));
    $this->actingAs($this->c->leader)->get($this->url)->assertInertia(fn ($p) => $p->where('posts.0.comments.1.sendUrl', null)); // only the asker gets the button
});

it('[8] posts a win only when both teams agree, with no client details', function () {
    $ans = [];
    foreach (app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'] as $f) {
        $ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }
    $r = app(Referrals::class)->send($this->a->leader, $this->g, $this->cities[1], [...$ans, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100'], true, null)['referral'];
    app(Referrals::class)->accept($r->fresh(), $this->b->leader);
    $this->actingAs($this->b->leader)->post("/app/referrals/{$r->ulid}/share-win")->assertForbidden(); // not closed yet
    app(Pipeline::class)->move($r->fresh(), $this->b->leader, 'won', null, ['closing_date' => '2026-12-20', 'closing_value' => 30000]);
    app(Closing::class)->confirm($r->fresh(), $this->a->leader);
    $this->post("/app/referrals/{$r->ulid}/share-win")->assertSessionHas('status', 'win-waiting');
    expect(DB::table('feed_posts')->where('kind', 'win')->count())->toBe(0);
    Notification::assertSentTo($this->a->leader, InAppNotification::class, fn ($n) => str_contains($n->title, 'would like to share your win'));
    $this->actingAs($this->a->leader)->post("/app/referrals/{$r->ulid}/share-win")->assertSessionHas('status', 'win-posted');
    $win = DB::table('feed_posts')->where('kind', 'win')->first();
    expect($win->body)->toBe('Hurricane Sun closed a deal thanks to a referral from Red Rock Solar. Thank you!')->not->toContain('Tom')->not->toContain('30');
});

it('[9] notifies mentions and replies instantly, and bundles everything else into each member’s digest', function () {
    $this->actingAs($this->a->leader)->post($this->url.'/posts', ['kind' => 'post', 'body' => 'Great job @'.$this->b->fresh()->slug.' on the training!']);
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'feed.mention');
    Notification::assertNotSentTo($this->c->leader, InAppNotification::class, fn ($n) => str_starts_with($n->type, 'feed.')); // plain posts don't ping
    $this->b->leader->forceFill(['feed_digest' => 'daily'])->save();
    $this->c->leader->forceFill(['feed_digest' => 'off'])->save();
    $this->travelTo(now()->next('Tuesday')->setTime(7, 0));
    $this->actingAs($this->a->leader)->post($this->url.'/posts', ['kind' => 'post', 'body' => 'Fresh news for the digest']);
    $this->travel(30)->minutes();
    Artisan::call('t2t:feed:digest');
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'feed.digest' && str_contains($n->title, '1 new post'));
    Notification::assertNotSentTo($this->c->leader, InAppNotification::class, fn ($n) => $n->type === 'feed.digest');
    Notification::assertNotSentTo($this->owner, InAppNotification::class, fn ($n) => $n->type === 'feed.digest'); // weekly: Mondays only
    Artisan::call('t2t:feed:digest');
    expect(Notification::sent($this->b->leader, InAppNotification::class)->filter(fn ($n) => $n->type === 'feed.digest'))->toHaveCount(1); // nothing new, nothing sent
    $this->actingAs($this->b->leader)->post('/app/feed/digest', ['digest' => 'weekly'])->assertSessionHas('status', 'digest-saved');
});

it('[10] pages older posts for endless scroll and announces new posts in real time to members only', function () {
    Event::fake([FeedChanged::class]);
    foreach (range(1, 25) as $i) {
        DB::table('feed_posts')->insert(['group_id' => $this->g->id, 'team_id' => $this->a->id, 'user_id' => $this->a->leader_id, 'kind' => 'post', 'body' => "Post {$i}", 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->actingAs($this->b->leader)->get($this->url)->assertInertia(fn ($p) => $p->has('posts', 20)->where('posts.0.body', 'Post 25'));
    $older = $this->getJson($this->url.'/more?before='.DB::table('feed_posts')->where('body', 'Post 6')->value('id'))->assertOk()->json('posts');
    expect(array_column($older, 'body'))->toBe(['Post 5', 'Post 4', 'Post 3', 'Post 2', 'Post 1']);
    $this->actingAs(User::factory()->create())->getJson($this->url.'/more?before=999')->assertForbidden();

    $this->actingAs($this->a->leader)->post($this->url.'/posts', ['kind' => 'post', 'body' => 'Live!']);
    Event::assertDispatched(FeedChanged::class, fn ($e) => $e->group === 'solar-installers-network' && $e->broadcastOn()[0]->name === 'private-groups.solar-installers-network' && $e->broadcastWith() === ['post' => ($this->postId)()]);
    expect(App\Domains\Community\Feed::member($this->b->leader, $this->g))->toBeTrue()->and(App\Domains\Community\Feed::member(User::factory()->create(), $this->g))->toBeFalse();
});
