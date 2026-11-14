<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Consumer;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Phone\PhoneNumber;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use App\Support\Sms\SmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * "Find a trusted pro": a member of the public asks for help on a city or industry page. The phone number is
 * checked with a text code; the request then goes to the seat holder, then the backup, then the open request
 * board, as a referral marked "consumer" (sent by the platform's website team). One request goes to one pro at a
 * time and is never sold to several. A per-request fee is recorded on acceptance only when an admin has set one.
 */
final class ConsumerRequests
{
    public const TIMING = ['now' => 'As soon as possible', 'weeks' => 'In the next few weeks', 'months' => 'In the next few months', 'exploring' => 'Just exploring'];

    public const CONTACT = ['call' => 'Phone call', 'text' => 'Text message', 'email' => 'Email'];

    public const BEST_TIME = ['anytime' => 'Any time', 'morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Evening'];

    public const DUPLICATE_DAYS = 30;

    public const CODE_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly Referrals $referrals, private readonly IndustryTemplates $templates, private readonly Notifier $notifier, private readonly SmsSender $sms) {}

    /** Requests are taken only in live public groups whose industry allows them (never in directory industries). */
    public static function allowed(Group $group): bool
    {
        return $group->status === 'live' && ! $group->is_private && $group->industry->tier->allowsConsumerRequests();
    }

    /** The website team every consumer request is "sent" by: no seat, no public profile, in-app notices only. */
    public static function houseTeam(): Team
    {
        $user = User::query()->where('email', 'website-requests@platform.invalid')->first();
        if ($user === null) {
            $user = User::query()->forceCreate(['first_name' => (string) config('seo.site_name'), 'last_name' => 'Website', 'email' => 'website-requests@platform.invalid',
                'password' => bcrypt(Str::random(40)), 'email_verified_at' => now(), 'is_active' => true]);
        }
        $team = Team::forLeader($user);
        if ($team->name !== 'Website request' || $team->profile_public) {
            $team->forceFill(['name' => 'Website request', 'profile_public' => false])->saveQuietly();
        }

        return $team;
    }

    /** @return list<array<string, mixed>> the industry's request questions (its referral form without the client's contact fields) */
    public function questions(Group $group): array
    {
        $fields = array_filter($this->templates->resolve('form', $group->industry, $group->id)['body'], fn ($f) => ! in_array($f['key'], ['client_name', 'client_phone', 'client_email'], true));

        // The form is written for a pro describing a client ("Do they own the home?"); here the client answers.
        return array_values(array_map(fn ($f) => [...$f, 'label' => self::forClient((string) ($f['label'] ?? $f['key']))], $fields));
    }

    /** "Do they own the home?" becomes "Do you own the home?", for the person asking. */
    public static function forClient(string $label): string
    {
        // Possessives first ("the client's budget" is "your budget"), then the rest. Unicode-aware for ’.
        $map = ["/\\b(the )?client['’]s\\b/iu" => 'your', '/\\bis (the client|they)\\b/iu' => 'are you', '/\\bdoes (the client|they)\\b/iu' => 'do you',
            '/\\bhas (the client|they)\\b/iu' => 'have you', '/\\bthe client\\b/iu' => 'you', '/\\bthey\\b/iu' => 'you', '/\\btheir\\b/iu' => 'your', '/\\bthem\\b/iu' => 'you'];
        $out = (string) preg_replace(array_keys($map), array_values($map), $label);

        return ucfirst($out);
    }

    /**
     * Start a request: checks, a duplicate guard, then a text code to confirm the phone number.
     *
     * @param  array<string, mixed>  $d  name, phone, email, contact_method, best_time, timing, answers, consent
     * @return array{ulid: string, duplicate: bool}
     */
    public function start(Group $group, City $city, array $d, ?string $sourcePath, ?string $ipHash): array
    {
        if (! self::allowed($group)) {
            throw ValidationException::withMessages(['group' => 'Requests aren’t taken in this network.']);
        }
        if (! $city->is_enabled) {
            throw ValidationException::withMessages(['city_id' => 'We’re not taking requests in this location yet.']);
        }
        $phone = PhoneNumber::normalize((string) ($d['phone'] ?? ''));
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'Enter a mobile number we can text a code to.']);
        }
        if (empty($d['consent'])) {
            throw ValidationException::withMessages(['consent' => 'Please agree to be contacted so a pro can call you.']);
        }
        $answers = [];
        $errors = [];
        foreach ($this->questions($group) as $f) {
            $v = $d['answers'][$f['key']] ?? null;
            if (($f['required'] ?? false) && ($v === null || $v === '' || $v === [])) {
                $errors['answers.'.$f['key']] = ($f['label'] ?? $f['key']).' is required.';
            }
            if ($v !== null && $v !== '') {
                $answers[$f['key']] = is_array($v) ? array_values(array_map(fn ($x) => mb_substr(strip_tags((string) $x), 0, 200), $v)) : (is_bool($v) ? $v : mb_substr(strip_tags((string) $v), 0, 2000));
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $email = ! empty($d['email']) ? strtolower(trim((string) $d['email'])) : null;
        // The same person asking the same network twice in 30 days isn't sent twice.
        $duplicate = DB::table('consumer_requests')->where('group_id', $group->id)->whereNotIn('status', ['unverified', 'duplicate'])->where('created_at', '>=', now()->subDays(self::DUPLICATE_DAYS))
            ->where(fn ($q) => $q->where('phone', $phone)->when($email, fn ($w) => $w->orWhere('email', $email)))->exists();
        $ulid = (string) Str::ulid();
        $t = $group->industry->terms();
        DB::table('consumer_requests')->insert([
            'ulid' => $ulid, 'group_id' => $group->id, 'city_id' => $city->id, 'name' => mb_substr(strip_tags((string) $d['name']), 0, 160), 'phone' => $phone, 'email' => $email,
            'contact_method' => array_key_exists((string) ($d['contact_method'] ?? ''), self::CONTACT) ? $d['contact_method'] : 'call',
            'best_time' => array_key_exists((string) ($d['best_time'] ?? ''), self::BEST_TIME) ? $d['best_time'] : 'anytime',
            'timing' => array_key_exists((string) ($d['timing'] ?? ''), self::TIMING) ? $d['timing'] : 'exploring', 'answers' => json_encode($answers),
            'consent_text' => "I agree to be contacted about my request by one verified {$t['pro']} introduced by ".config('seo.site_name').', by phone, text or email. Message and data rates may apply; reply STOP to opt out.',
            'consent_at' => now(), 'status' => $duplicate ? 'duplicate' : 'unverified', 'source_path' => $sourcePath !== null ? mb_substr($sourcePath, 0, 200) : null, 'ip_hash' => $ipHash,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $duplicate) {
            $this->sendCode($ulid);
        }

        return ['ulid' => $ulid, 'duplicate' => $duplicate];
    }

    public function find(string $ulid): ?stdClass
    {
        $r = DB::table('consumer_requests')->where('ulid', $ulid)->first();

        return $r instanceof stdClass ? $r : null;
    }

    /** A fresh 6-digit code by text (codes expire after 10 minutes). */
    public function sendCode(string $ulid): void
    {
        $req = $this->find($ulid) ?? throw ValidationException::withMessages(['code' => 'Request not found.']);
        $code = (string) random_int(100000, 999999);
        DB::table('consumer_requests')->where('id', $req->id)->update(['code_hash' => hash('sha256', $code), 'code_expires_at' => now()->addMinutes(self::CODE_MINUTES), 'code_attempts' => 0, 'updated_at' => now()]);
        $this->sms->send((string) $req->phone, "Your code is {$code}. Enter it to send your request through ".config('seo.site_name').'. It expires in '.self::CODE_MINUTES.' minutes.');
    }

    /** Check the code; on success the request is placed with the right pro. */
    public function verify(string $ulid, string $code): stdClass
    {
        $req = $this->find($ulid) ?? throw ValidationException::withMessages(['code' => 'Request not found.']);
        if ($req->status !== 'unverified') {
            return $req;
        }
        if ($req->code_hash === null || $req->code_attempts >= self::MAX_ATTEMPTS || now()->greaterThan((string) $req->code_expires_at)) {
            throw ValidationException::withMessages(['code' => 'That code has expired. Send a new one.']);
        }
        if (! hash_equals((string) $req->code_hash, hash('sha256', trim($code)))) {
            DB::table('consumer_requests')->where('id', $req->id)->increment('code_attempts');
            throw ValidationException::withMessages(['code' => 'That code isn’t right. Check the text and try again.']);
        }
        DB::table('consumer_requests')->where('id', $req->id)->update(['phone_verified_at' => now(), 'code_hash' => null, 'updated_at' => now()]);
        $this->place((string) $req->ulid);

        return $this->find($ulid) ?? $req;
    }

    /** Place a checked request with the seat holder (or backup); with nobody available it goes to the open board. */
    public function place(string $ulid): void
    {
        $req = $this->find($ulid);
        if ($req === null) {
            return;
        }
        $group = Group::query()->with('industry')->findOrFail((int) $req->group_id);
        $city = City::query()->findOrFail((int) $req->city_id);
        $route = $this->referrals->route($group, $city);
        if ($route['team'] === null) {
            $this->board($req, $group, $route['seat']);

            return;
        }
        $ref = $this->referrals->placeConsumerRequest($group, $route['seat'], $route['team'], $route['role'], self::houseTeam(), $req, $this->answersFor($req));
        DB::table('consumer_requests')->where('id', $req->id)->update(['status' => 'routed', 'referral_id' => $ref->id, 'updated_at' => now()]);
        $t = $group->industry->terms();
        $this->sms->send((string) $req->phone, "Thanks, {$this->first($req)}. Your request went to {$route['team']->name}, the verified {$t['pro']} for {$route['seat']->territory_name}. Follow it here: ".url('/r/'.$ref->client_token).' Reply STOP to opt out.');
    }

    /** The receiving pro passed and there was no backup: post the request on the open board for the network. */
    public function toBoard(Referral $ref): void
    {
        $req = DB::table('consumer_requests')->where('referral_id', $ref->id)->first();
        if ($req instanceof stdClass) {
            $this->board($req, $ref->group, $ref->seat);
        }
    }

    /**
     * Requests on the open board a team can take: in networks it has been approved into, in states where it holds a seat.
     *
     * @return array<int, array<string, mixed>>
     */
    public function boardFor(Team $team): array
    {
        $groups = DB::table('seat_applications')->where(['team_id' => $team->id, 'status' => 'approved'])->pluck('group_id');
        $states = DB::table('seats')->where('primary_team_id', $team->id)->orWhere('backup_team_id', $team->id)->pluck('state_code')->filter()->unique();
        $tried = fn (int $refId) => DB::table('referral_events')->where('referral_id', $refId)->whereIn('event', ['declined', 'expired'])->pluck('team_id')->all();

        return DB::table('consumer_requests as c')->join('cities', 'cities.id', '=', 'c.city_id')->join('states', 'states.id', '=', 'cities.state_id')->join('groups', 'groups.id', '=', 'c.group_id')
            ->where('c.status', 'board')->whereIn('c.group_id', $groups)->whereIn('states.code', $states)->orderByDesc('c.boarded_at')
            ->get(['c.ulid', 'c.name', 'c.timing', 'c.answers', 'c.boarded_at', 'c.referral_id', 'cities.name as city', 'states.code as state', 'groups.name as group'])
            ->filter(fn ($r) => $r->referral_id === null || ! in_array($team->id, $tried((int) $r->referral_id), true))
            ->map(fn ($r) => ['id' => (string) $r->ulid, 'client' => Referral::shorten((string) $r->name), 'city' => $r->city.', '.$r->state, 'group' => (string) $r->group,
                'timing' => self::TIMING[$r->timing] ?? $r->timing, 'answers' => (array) json_decode((string) $r->answers, true), 'since' => (string) $r->boarded_at])->values()->all();
    }

    /** A team takes a request from the open board: it is placed with them and accepted at once. */
    public function take(string $ulid, User $user): Referral
    {
        $team = Team::of($user) ?? throw ValidationException::withMessages(['request' => 'Join a team first.']);
        $mine = collect($this->boardFor($team))->firstWhere('id', $ulid);
        if ($mine === null) {
            throw ValidationException::withMessages(['request' => 'This request is no longer available.']);
        }
        $req = $this->find($ulid) ?? throw ValidationException::withMessages(['request' => 'Request not found.']);
        $group = Group::query()->with('industry')->findOrFail((int) $req->group_id);
        $seat = $this->referrals->route($group, City::query()->findOrFail((int) $req->city_id))['seat'];
        $claimed = DB::table('consumer_requests')->where('id', $req->id)->where('status', 'board')->update(['status' => 'claimed', 'updated_at' => now()]);
        if ($claimed === 0) {
            throw ValidationException::withMessages(['request' => 'Another team just took this request.']);
        }
        $ref = $this->referrals->placeConsumerRequest($group, $seat, $team, 'board', self::houseTeam(), $req, $this->answersFor($req));
        DB::table('consumer_requests')->where('id', $req->id)->update(['referral_id' => $ref->id, 'updated_at' => now()]);
        $this->referrals->accept($ref, $user);
        $this->sms->send((string) $req->phone, "Good news, {$this->first($req)}: {$team->name} will contact you about your request. Follow it here: ".url('/r/'.$ref->client_token).' Reply STOP to opt out.');

        return $ref;
    }

    /** On acceptance: a per-request fee is recorded only if an admin has set one (off by default: included in the seat). */
    public static function accepted(Referral $ref): void
    {
        $fee = (int) round(100 * (float) app(Settings::class)->get('billing.consumer_request_fee', '0'));
        if ($fee > 0) {
            DB::table('consumer_requests')->where('referral_id', $ref->id)->whereNull('fee_cents')->update(['fee_cents' => $fee, 'updated_at' => now()]);
        }
    }

    private function board(stdClass $req, Group $group, Seat $seat): void
    {
        DB::table('consumer_requests')->where('id', $req->id)->update(['status' => 'board', 'boarded_at' => now(), 'updated_at' => now()]);
        $this->sms->send((string) $req->phone, "Thanks, {$this->first($req)}. We’re finding a verified pro for {$seat->territory_name} and will text you as soon as one takes your request. Reply STOP to opt out.");
        // Tell teams in this network that hold a seat in the same state (they can take it from the board).
        $teams = Team::query()->with('leader')->whereIn('id', DB::table('seats')->where(['group_id' => $group->id, 'state_code' => $seat->state_code])->whereNotNull('primary_team_id')->pluck('primary_team_id'))->limit(25)->get();
        foreach ($teams as $t) {
            $this->notifier->send($t->leader, 'network.announcement', ['body' => 'A new website request near '.$seat->territory_name.' is on the open request board. The first team to take it gets it.'], url('/app/requests'), $group->industry, $group->id);
        }
    }

    /** @return array<string, mixed> the industry answers, plus how and when the client wants to be contacted */
    private function answersFor(stdClass $req): array
    {
        return [...(array) json_decode((string) $req->answers, true), 'client_name' => (string) $req->name, 'client_phone' => (string) $req->phone,
            'client_email' => $req->email, 'how_soon' => self::TIMING[$req->timing] ?? $req->timing,
            'contact_by' => self::CONTACT[$req->contact_method] ?? $req->contact_method, 'best_time' => self::BEST_TIME[$req->best_time] ?? $req->best_time];
    }

    private function first(stdClass $req): string
    {
        return (string) Str::of((string) $req->name)->trim()->explode(' ')->first();
    }
}
