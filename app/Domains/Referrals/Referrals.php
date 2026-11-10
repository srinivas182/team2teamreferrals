<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Phone\PhoneNumber;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Territory;
use App\Domains\Network\Training\Training;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The referral engine: route to the right seat, send with the client's consent, accept or decline within the
 * group's deadline, and pass to the backup automatically on a decline or a missed deadline. Every step is on the
 * referral's timeline and both sides are told.
 */
final class Referrals
{
    public const DUPLICATE_DAYS = 90;

    public function __construct(private readonly Training $training, private readonly Notifier $notifier, private readonly IndustryTemplates $templates) {}

    /** @return array{seat: Seat, team: ?Team, role: string, territory: string} */
    public function route(Group $group, City $city): array
    {
        $t = Territory::fromCity($group->industry->territory_unit, $city);
        $seat = Seat::query()->with(['primary', 'backup', 'group'])->firstOrCreate(['group_id' => $group->id, 'territory_type' => $t['territory_type'], 'territory_id' => $t['territory_id']], $t);
        $team = $this->training->receiver($seat);

        return ['seat' => $seat, 'team' => $team, 'role' => $team !== null && $team->id === $seat->primary_team_id ? 'holder' : 'backup', 'territory' => $t['territory_name']];
    }

    /** Groups the person's team can send in: ones it has been approved into. */
    /** @return list<Group> */
    public function groupsFor(User $user): array
    {
        $team = Team::of($user);
        if ($team === null) {
            return [];
        }
        $ids = DB::table('seat_applications')->where(['team_id' => $team->id, 'status' => 'approved'])->pluck('group_id')->unique();

        // Directory (Tier C) networks never route clients between members: they're listings only.
        return array_values(Group::query()->with('industry')->whereIn('id', $ids)->whereHas('industry', fn ($q) => $q->where('tier', '!=', \App\Domains\Network\Industries\Tier::Directory->value))->orderBy('name')->get()->all());
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array{referral: Referral, duplicate: false}|array{referral: null, duplicate: true}
     */
    public function send(User $sender, Group $group, City $city, array $answers, bool $consent, ?string $notes, bool $confirmDuplicate = false, bool $textClient = false): array
    {
        $team = Team::of($sender) ?? Team::forLeader($sender);
        if ($group->industry->tier === \App\Domains\Network\Industries\Tier::Directory) {
            throw ValidationException::withMessages(['group' => 'This is a directory network: clients find pros in the public directory, and members don’t send referrals to each other here.']);
        }
        if (! in_array($group->id, array_map(fn (Group $g) => $g->id, $this->groupsFor($sender)), true)) {
            throw ValidationException::withMessages(['group' => 'You can send referrals in groups you’ve been approved into.']);
        }
        if ($this->training->blocks($sender, $group)) {
            throw ValidationException::withMessages(['group' => 'Finish your network training to start sending referrals in this group.']);
        }
        if (! $consent) {
            throw ValidationException::withMessages(['consent' => 'Your client must agree to be contacted before you send a referral.']);
        }
        $form = $this->templates->resolve('form', $group->industry, $group->id)['body'];
        $clean = [];
        $errors = [];
        foreach ($form as $f) {
            $key = (string) $f['key'];
            $v = $answers[$key] ?? null;
            if (($f['required'] ?? false) && ($v === null || $v === '' || $v === [])) {
                $errors["answers.{$key}"] = ($f['label'] ?? $key).' is required.';
            }
            if ($v !== null && $v !== '') {
                $clean[$key] = is_array($v) ? array_values(array_map('strval', $v)) : (is_bool($v) ? $v : mb_substr((string) $v, 0, 2000));
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $str = fn (string $k): ?string => isset($clean[$k]) && is_string($clean[$k]) ? $clean[$k] : null;
        $phone = $str('client_phone') !== null ? (PhoneNumber::normalize((string) $str('client_phone')) ?? $str('client_phone')) : null;
        $email = $str('client_email') !== null ? strtolower((string) $str('client_email')) : null;
        $clientName = $str('client_name') ?? 'Client';

        $route = $this->route($group, $city);
        if ($route['team'] === null) {
            throw ValidationException::withMessages(['city_id' => "No verified pro can take referrals in {$route['territory']} yet."]);
        }
        if ($route['team']->id === $team->id) {
            throw ValidationException::withMessages(['city_id' => 'That’s your own territory.']);
        }
        if (! $confirmDuplicate && ($phone || $email) && Referral::query()->where('group_id', $group->id)->where('created_at', '>', now()->subDays(self::DUPLICATE_DAYS))
            ->where(fn ($q) => $q->when($phone, fn ($q) => $q->orWhere('client_phone', $phone))->when($email, fn ($q) => $q->orWhere('client_email', $email)))->exists()) {
            return ['referral' => null, 'duplicate' => true];
        }

        $receiver = $route['team'];
        $ref = DB::transaction(function () use ($group, $route, $receiver, $team, $sender, $clean, $phone, $email, $notes, $clientName) {
            $ref = Referral::create([
                'client_token' => \Illuminate\Support\Str::random(40),
                'group_id' => $group->id, 'seat_id' => $route['seat']->id, 'sender_team_id' => $team->id, 'sender_user_id' => $sender->id, 'receiver_team_id' => $receiver->id,
                'routed_to' => $route['role'], 'client_name' => $clientName, 'client_phone' => $phone, 'client_email' => $email, 'answers' => $clean, 'notes' => $notes,
                'consent_at' => now(), 'consent_method' => 'sender_attested', 'consent_text' => self::consentText($group),
                'respond_by' => now()->addHours((int) $group->rule('accept_hours')),
            ]);
            $this->event($ref, 'sent', $team, $sender, ['to' => $receiver->name, 'role' => $route['role']]);
            $ref->forceFill(['temperature' => Nurture::classify($ref)])->save();

            return $ref;
        });
        $this->tellReceiver($ref);
        if ($textClient) {
            $this->requestClientConsent($ref);
        }

        return ['referral' => $ref, 'duplicate' => false];
    }

    public function accept(Referral $ref, User $user): void
    {
        $this->mustReceive($ref, $user);
        abort_unless($ref->status === 'sent', 422, 'This referral is no longer waiting for a decision.');
        $ref->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
        app(Pipeline::class)->start($ref);
        app(Nurture::class)->onAccepted($ref);
        Events\ReferralChanged::dispatch($ref, 'status');
        $this->event($ref, 'accepted', $ref->receiver, $user);
        app(ReferralAgreements::class)->start($ref);
        $this->notifier->send($ref->sender->leader, 'referral.accepted', ['client' => $ref->client_name, 'city' => $ref->seat->territory_name, 'team' => (string) $ref->receiver?->name], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
    }

    public function decline(Referral $ref, User $user, string $reason): void
    {
        $this->mustReceive($ref, $user);
        abort_unless($ref->status === 'sent', 422, 'This referral is no longer waiting for a decision.');
        $this->passOn($ref, $user, 'declined', $reason);
    }

    /** Hourly: referrals past their deadline pass to the backup (or close) as if declined. */
    public function expireOverdue(): int
    {
        $n = 0;
        foreach (Referral::query()->with(['group', 'seat', 'receiver', 'sender'])->where('status', 'sent')->where('respond_by', '<=', now())->get() as $ref) {
            $this->passOn($ref, null, 'expired', 'No response before the deadline');
            $n++;
        }

        return $n;
    }

    /** Who may see a referral: the sending team, and the team that currently has it. */
    public function canView(Referral $ref, User $user): bool
    {
        $team = Team::of($user);

        return $team !== null && ($team->id === $ref->sender_team_id || $team->id === $ref->receiver_team_id);
    }

    /** One-tap link for the current receiver: opens a confirm screen (never accepts on open, so email scanners can't). */
    public static function quickLink(Referral $ref): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute('referrals.quick', $ref->respond_by, ['referral' => $ref->ulid, 'team' => (int) $ref->receiver_team_id]);
    }

    /** @param list<\Illuminate\Http\UploadedFile> $files */
    public function message(Referral $ref, User $user, string $body, array $files = []): void
    {
        $team = Team::of($user);
        abort_unless($team !== null && in_array($team->id, [$ref->sender_team_id, $ref->receiver_team_id], true), 403);
        $messageId = DB::table('referral_messages')->insertGetId(['referral_id' => $ref->id, 'team_id' => $team->id, 'user_id' => $user->id, 'body' => mb_substr(trim($body), 0, 4000), 'created_at' => now()]);
        if ($files !== []) {
            Attachments::store($ref, $team, $user, (int) $messageId, $files);
            $body = trim($body.' ['.count($files).' file'.(count($files) > 1 ? 's' : '').' attached]');
        }
        $ref->loadMissing(['sender.leader', 'receiver.leader', 'group.industry']);
        $other = $team->id === $ref->sender_team_id ? $ref->receiver : $ref->sender;
        Events\ReferralChanged::dispatch($ref, 'message');
        if ($other !== null && ! Conversations::muted($ref, $other->leader)) {
            $toReceiverEarly = $other->id === $ref->receiver_team_id && $ref->status !== 'accepted';
            $excerpt = mb_strimwidth($toReceiverEarly ? Referral::maskContacts($body) : $body, 0, 160, '…');
            $name = $toReceiverEarly ? $ref->shortClientName() : $ref->client_name;
            $this->notifier->send($other->leader, 'referral.message', ['headline' => "{$team->name} sent a message about {$name}", 'body' => $excerpt, '_reply_to' => ReplyAddress::for($ref, $other->leader)], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
        }
    }

    /** Whether the client can be asked to confirm by text: industry approved, a mobile on file, not opted out. */
    public static function canTextClient(Referral $ref): bool
    {
        return $ref->group->industry->client_texting_approved && $ref->client_phone !== null && $ref->consent_requested_at === null
            && ! DB::table('sms_opt_outs')->where('phone', $ref->client_phone)->whereNotNull('opted_out_at')->exists();
    }

    /** One text per referral asking the client to confirm they want to be contacted. */
    public function requestClientConsent(Referral $ref): bool
    {
        $ref->loadMissing(['group.industry', 'seat', 'sender']);
        if (! self::canTextClient($ref)) {
            return false;
        }
        $token = \Illuminate\Support\Str::random(40);
        $ref->forceFill(['consent_token_hash' => hash('sha256', $token), 'consent_requested_at' => now()])->save();
        $t = $ref->group->industry->terms();
        app(\App\Support\Sms\SmsSender::class)->send((string) $ref->client_phone, "{$ref->sender->name} would like to connect you with a verified {$t['pro']} in {$ref->seat->territory_name} via Team to Team Referrals. Reply YES or tap to confirm: ".url('/c/'.$token).' Reply STOP to opt out.');
        DB::table('referral_events')->insert(['referral_id' => $ref->id, 'event' => 'consent_requested', 'team_id' => $ref->sender_team_id, 'actor_id' => null, 'meta' => null, 'created_at' => now()]);

        return true;
    }

    public function confirmClient(Referral $ref, string $via): void
    {
        if ($ref->client_confirmed_at !== null) {
            return;
        }
        $ref->loadMissing(['group.industry', 'sender.leader']);
        $ref->forceFill(['client_confirmed_at' => now(), 'consent_method' => 'client_confirmed', 'consent_token_hash' => null])->save();
        DB::table('referral_events')->insert(['referral_id' => $ref->id, 'event' => 'client_confirmed', 'team_id' => null, 'actor_id' => null, 'meta' => json_encode(['via' => $via]), 'created_at' => now()]);
        if ($ref->client_phone !== null && $ref->client_token !== null) {
            app(\App\Support\Sms\SmsSender::class)->send($ref->client_phone, 'Thanks! See who will contact you and track your introduction: '.url('/r/'.$ref->client_token));
        }
        $this->notifier->send($ref->sender->leader, 'referral.message', ['headline' => "{$ref->client_name} confirmed by text", 'body' => 'Your client confirmed they want to be contacted. Their consent is now recorded as client-confirmed.'], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
    }

    /** A YES reply to our number confirms the newest pending request from that phone (within 7 days). */
    public function confirmByReply(string $fromE164): bool
    {
        $ref = Referral::query()->where('client_phone', $fromE164)->whereNull('client_confirmed_at')->where('consent_requested_at', '>=', now()->subDays(7))->latest('consent_requested_at')->first();
        if ($ref === null) {
            return false;
        }
        $this->confirmClient($ref, 'reply');

        return true;
    }

    public static function consentText(Group $group): string
    {
        $t = $group->industry->terms();

        return "The {$t['client']} agreed to be contacted by a verified {$t['pro']} in their area through Team to Team Referrals, and to share these details for that purpose.";
    }

    private function passOn(Referral $ref, ?User $actor, string $why, string $reason): void
    {
        Events\ReferralChanged::dispatch($ref, 'status');
        $from = $ref->receiver;
        $this->event($ref, $why, $from, $actor, ['reason' => $reason]);
        $backup = $ref->seat->backup;
        $tried = DB::table('referral_events')->where('referral_id', $ref->id)->whereIn('event', ['declined', 'expired'])->pluck('team_id')->all();
        $next = $backup !== null && ! in_array($backup->id, $tried, true) && $backup->id !== $ref->sender_team_id && ! $this->training->blocks($backup->leader, $ref->group) ? $backup : null;
        $data = ['client' => $ref->client_name, 'city' => $ref->seat->territory_name, 'team' => (string) $from?->name];
        if ($next !== null) {
            $ref->forceFill(['receiver_team_id' => $next->id, 'routed_to' => 'backup', 'respond_by' => now()->addHours((int) $ref->group->rule('accept_hours')), 'decline_reason' => $reason])->save();
            $this->event($ref, 'passed_to_backup', $next, null);
            $this->notifier->send($ref->sender->leader, 'referral.declined', $data, url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
            $this->tellReceiver($ref->fresh() ?? $ref);

            return;
        }
        $ref->forceFill(['status' => $why === 'expired' ? 'expired' : 'declined', 'decline_reason' => $reason])->save();
        $this->notifier->send($ref->sender->leader, 'network.announcement', ['body' => "Your referral for {$ref->client_name} in {$ref->seat->territory_name} couldn’t be placed: {$reason}. No backup was available. You can send it again later or contact us."], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
    }

    private function tellReceiver(Referral $ref): void
    {
        $ref->loadMissing(['receiver', 'sender', 'seat', 'group']);
        if ($ref->receiver === null) {
            return;
        }
        $this->notifier->send($ref->receiver->leader, 'referral.waiting', [
            'client' => $ref->shortClientName(), 'city' => $ref->seat->territory_name, 'team' => $ref->sender->name, 'hours' => (int) $ref->group->rule('accept_hours'),
        ], self::quickLink($ref), $ref->group->industry, $ref->group_id);
    }

    private function mustReceive(Referral $ref, User $user): void
    {
        $team = Team::of($user);
        abort_unless($team !== null && $team->id === $ref->receiver_team_id, 403);
    }

    /** @param array<string, mixed> $meta */
    private function event(Referral $ref, string $event, ?Team $team, ?User $actor, array $meta = []): void
    {
        DB::table('referral_events')->insert(['referral_id' => $ref->id, 'event' => $event, 'team_id' => $team?->id, 'actor_id' => $actor?->id, 'meta' => $meta === [] ? null : json_encode($meta), 'created_at' => now()]);
    }
}
