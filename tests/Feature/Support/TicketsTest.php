<?php

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Support\Ticket;
use App\Domains\Support\TicketMail;
use App\Domains\Support\Tickets;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2027-03-03 10:00:00'));
    Notification::fake();
    Storage::fake('private');
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    config(['mail.inbound.domain' => 'in.example.test', 'mail.inbound.secret' => 's3cret']);
    $this->agent = User::factory()->create(['first_name' => 'Sam', 'last_name' => 'Support', 'two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->agent->assignRole(Permissions::STAFF);
    $this->agent->givePermissionTo('support.manage');
    $this->other = User::factory()->create(['first_name' => 'Ola', 'two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->other->assignRole(Permissions::STAFF);
    $this->other->givePermissionTo('support.manage');
    $this->member = User::factory()->create(['first_name' => 'Mia', 'last_name' => 'Member']);
    $this->started = fn () => encrypt(now()->subSeconds(30)->getTimestamp());
});

function ticketMails(string $email): array
{
    $out = [];
    Notification::assertSentOnDemand(TicketMail::class, function ($n, $ch, $to) use ($email, &$out) {
        if ($to->routes['mail'] === $email) {
            $out[] = $n;
        }

        return true;
    });

    return $out;
}

it('lets a visitor open a ticket with a file, follow it by private link, and reply by web or email', function () {
    $this->get('/support/new?q=refund+invoice')->assertOk()->assertInertia(fn ($p) => $p->component('help/Contact')->where('me', null)->where('subject', 'refund invoice'));
    $base = ['name' => 'Vic Visitor', 'email' => 'Vic@Example.org', 'subject' => 'Question about partners', 'body' => 'How long does review take for my listing?', 'category' => 'partners'];
    $this->post('/support', [...$base, 'started' => ($this->started)(), 'website_url' => 'x'])->assertSessionHasErrors('body');
    $this->post('/support', [...$base, 'started' => ($this->started)(), 'files' => [UploadedFile::fake()->createWithContent('a.pdf', 'not a pdf')]])->assertSessionHasErrors('files');
    $res = $this->post('/support', [...$base, 'started' => ($this->started)(), 'files' => [UploadedFile::fake()->image('shot.png', 40, 40)]]);
    $t = Ticket::query()->sole();
    $res->assertRedirect('/support/t/'.$t->ulid);
    expect($t->number)->toBe('T-'.(10000 + $t->id))->and($t->email)->toBe('vic@example.org')->and($t->status)->toBe('open')->and($t->category)->toBe('partners')
        ->and($t->first_response_due_at?->toDateTimeString())->toBe('2027-03-03 18:00:00') // normal priority: 8 hours
        ->and($t->messages->sole()->attachments[0]['name'])->toBe('shot.png');
    Storage::disk('private')->assertExists($t->messages->sole()->attachments[0]['path']);
    Notification::assertSentTo([$this->agent, $this->other], InAppNotification::class, fn ($n) => $n->type === 'admin.support');
    $confirm = ticketMails('vic@example.org')[0];
    expect($confirm->subject)->toBe("We got your message [{$t->number}]")->and($confirm->replyTo)->toMatch('/^reply\+t'.$t->ulid.'\.[0-9a-f]{16}@in\.example\.test$/')
        ->and($confirm->link)->toContain('/support/t/'.$t->ulid.'?k=');

    // The private link works (and is remembered for the session); without it, nothing.
    $link = parse_url($confirm->link);
    $this->get('/support/t/'.$t->ulid.'?'.$link['query'])->assertOk()->assertInertia(fn ($p) => $p->component('help/Ticket')->where('messages.0.author', 'customer'));
    $this->get('/support/t/'.$t->ulid.'/files/'.$t->messages->sole()->id.'/0')->assertOk();
    $this->post('/support/t/'.$t->ulid.'/reply', ['body' => 'Also: can I add a second city later?'])->assertSessionHas('status', 'ticket-replied');
    $this->flushSession();
    $this->get('/support/t/'.$t->ulid)->assertForbidden();
    $this->get('/support/t/'.$t->ulid.'?k=wrong')->assertForbidden();

    // Staff reply: emailed; the ticket waits on the customer. An internal note isn't emailed or shown to them.
    $this->actingAs($this->agent)->post('/admin/support/'.$t->ulid.'/reply', ['body' => 'Hi {first_name}, usually two business days.'])->assertSessionHas('status', 'ticket-reply-sent');
    $this->actingAs($this->agent)->post('/admin/support/'.$t->ulid.'/reply', ['body' => 'Checked the queue: it’s next.', 'internal' => true]);
    $t->refresh();
    expect($t->status)->toBe('pending')->and($t->assignee_id)->toBe($this->agent->id)->and($t->first_responded_at)->not->toBeNull();
    $mails = ticketMails('vic@example.org');
    expect(end($mails)->lines[0])->toBe('Hi Vic, usually two business days.')->and(collect($mails)->filter(fn ($m) => str_contains(implode(' ', $m->lines), 'Checked the queue')))->toBeEmpty();

    // The customer replies by email: only from their own address, signed address required, never twice.
    $email = fn (array $o) => $this->postJson('/webhooks/inbound-email', ['To' => $confirm->replyTo, 'From' => 'Vic <vic@example.org>', 'TextBody' => 'Great, thanks!', 'MessageID' => 'm-1', ...$o], ['X-Inbound-Secret' => 's3cret']);
    $email(['From' => 'mallory@example.org'])->assertJsonPath('status', 'wrong-sender');
    $email(['To' => 'reply+t'.$t->ulid.'.0000000000000000@in.example.test'])->assertJsonPath('status', 'unknown-ticket');
    $email([])->assertJsonPath('status', 'replied');
    $email([])->assertJsonPath('status', 'replied');
    expect($t->messages()->where('via', 'email')->count())->toBe(1)->and($t->fresh()?->status)->toBe('open');
    $this->get('/admin/support/'.$t->ulid)->assertOk()->assertInertia(fn ($p) => $p->where('messages', fn ($m) => collect($m)->where('internal', true)->count() === 1));
});

it('gives members their tickets in their account and keeps them private', function () {
    $this->actingAs($this->member)->get('/support/new')->assertInertia(fn ($p) => $p->where('me.email', $this->member->email));
    $this->actingAs($this->member)->post('/support', ['subject' => 'Invoice looks wrong', 'body' => 'My March invoice shows two seats.', 'category' => 'billing'])->assertSessionHas('status', 'ticket-opened');
    $t = Ticket::query()->sole();
    expect($t->user_id)->toBe($this->member->id)->and($t->name)->toBe('Mia Member')->and(ticketMails($this->member->email)[0]->link)->toBe(url('/app/support/'.$t->ulid));
    $this->get('/app/support')->assertOk()->assertInertia(fn ($p) => $p->component('help/Tickets')->where('tickets.0.number', $t->number));
    $this->get('/app/support/'.$t->ulid)->assertOk();
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get('/app/support/'.$t->ulid)->assertNotFound();
    $this->actingAs($stranger)->get('/admin/support')->assertForbidden();
    // Staff see who they are: their account and earlier tickets.
    $this->actingAs($this->agent)->get('/admin/support/'.$t->ulid)->assertInertia(fn ($p) => $p->where('context.member.name', 'Mia Member'));
    // Solve, rate; replying after close starts a new ticket that refers back.
    $this->actingAs($this->member)->post('/app/support/'.$t->ulid.'/rate', ['rating' => 'good'])->assertStatus(422);
    $this->actingAs($this->member)->post('/app/support/'.$t->ulid.'/solve')->assertSessionHas('status', 'ticket-solved');
    $this->actingAs($this->member)->post('/app/support/'.$t->ulid.'/rate', ['rating' => 'good', 'comment' => 'Quick!'])->assertSessionHas('status', 'ticket-rated');
    expect($t->fresh()?->rating)->toBe('good');
    $this->travel(8)->days();
    Tickets::run();
    expect($t->fresh()?->status)->toBe('closed');
    $this->actingAs($this->member)->post('/app/support/'.$t->ulid.'/reply', ['body' => 'It happened again in April.']);
    $new = Ticket::query()->where('id', '!=', $t->id)->sole();
    expect($new->subject)->toBe('Re: Invoice looks wrong')->and($new->messages->first()?->body)->toContain('Follows on from '.$t->number);
});

it('runs the inbox: views, assignment, priority targets, saved replies, alerts and auto-solve', function () {
    [$t] = Tickets::open(['name' => 'Ann', 'email' => 'ann@example.org', 'subject' => 'Cannot sign in', 'body' => 'Locked out since this morning.', 'category' => 'account'], null);
    $this->actingAs($this->agent)->get('/admin/support?view=unassigned')->assertOk()->assertInertia(fn ($p) => $p->component('admin/support/Index')->where('tickets.0.number', $t->number)
        ->where('tickets.0.due.overdue', false)->where('views', fn ($v) => collect($v)->firstWhere('key', 'open')['count'] === 1));
    // Urgent: one hour to first reply. Assigning tells the new owner.
    $this->post('/admin/support/'.$t->ulid.'/update', ['priority' => 'urgent', 'assignee_id' => $this->other->id])->assertSessionHas('status', 'ticket-updated');
    $t->refresh();
    expect($t->priority)->toBe('urgent')->and($t->first_response_due_at?->toDateTimeString())->toBe('2027-03-03 11:00:00')
        ->and($t->messages()->where('author', 'system')->value('body'))->toBe('Priority: Normal → Urgent. Assigned to Ola '.$this->other->last_name.'.');
    Notification::assertSentTo($this->other, InAppNotification::class, fn ($n) => $n->type === 'admin.support' && str_contains($n->title, 'is yours'));
    $this->post('/admin/support/'.$t->ulid.'/update', ['assignee_id' => $this->member->id])->assertSessionHasErrors('assignee_id'); // staff only
    $this->get('/admin/support?view=mine&q=sign')->assertInertia(fn ($p) => $p->where('tickets', []));

    // Past its response time: the assignee is alerted once.
    $this->travelTo(Carbon::parse('2027-03-03 11:30:00'));
    Tickets::run();
    Tickets::run();
    expect(collect(Notification::sent($this->other, InAppNotification::class))->filter(fn ($n) => str_contains($n->title, 'past its response time')))->toHaveCount(1);
    $this->actingAs($this->agent)->get('/admin/support')->assertInertia(fn ($p) => $p->where('stats.overdue', 1)->where('tickets.0.due.overdue', true));

    // Saved replies and targets.
    $this->post('/admin/support/macros', ['title' => 'Reset link', 'body' => 'Hi {first_name}, use “Forgot password” on the sign-in page.'])->assertSessionHas('status', 'macro-saved');
    $this->post('/admin/support/settings/sla', ['sla' => ['urgent' => ['first' => 2, 'resolve' => 1], 'high' => ['first' => 4, 'resolve' => 24], 'normal' => ['first' => 8, 'resolve' => 72], 'low' => ['first' => 24, 'resolve' => 120]]])
        ->assertSessionHasErrors('sla.urgent.resolve');
    $this->post('/admin/support/settings/sla', ['sla' => ['urgent' => ['first' => 2, 'resolve' => 8], 'high' => ['first' => 4, 'resolve' => 24], 'normal' => ['first' => 6, 'resolve' => 72], 'low' => ['first' => 24, 'resolve' => 120]]])
        ->assertSessionHas('status', 'settings-saved');
    expect(Tickets::sla('normal'))->toBe([6, 72]);

    // A reply waits on the customer; with no answer for 5 days it is solved, then closed 7 days later.
    $this->post('/admin/support/'.$t->ulid.'/reply', ['body' => DB::table('support_macros')->where('title', 'Reset link')->value('body')]);
    expect(collect(ticketMails('ann@example.org'))->last()->lines[0])->toBe('Hi Ann, use “Forgot password” on the sign-in page.');
    $this->travel(6)->days();
    Tickets::run();
    expect($t->fresh()?->status)->toBe('solved')->and(collect(ticketMails('ann@example.org'))->last()->lines[1])->toContain('marked this as solved');
    $this->travel(8)->days();
    Tickets::run();
    expect($t->fresh()?->status)->toBe('closed');
    $this->get('/admin/support?view=solved')->assertInertia(fn ($p) => $p->where('tickets.0.number', $t->number));
});

it('turns emails to support@ into tickets, once each', function () {
    $send = fn () => $this->postJson('/webhooks/inbound-email', ['To' => 'support@in.example.test', 'From' => $this->member->email, 'Subject' => 'Help with seats', 'TextBody' => 'How do I add a second city?', 'MessageID' => 'abc'],
        ['X-Inbound-Secret' => 's3cret']);
    $send()->assertJsonPath('status', 'opened');
    $send()->assertJsonPath('status', 'duplicate');
    $t = Ticket::query()->sole();
    expect($t->source)->toBe('email')->and($t->user_id)->toBe($this->member->id)->and($t->subject)->toBe('Help with seats')->and($t->messages->sole()->via)->toBe('email');
});
