<?php

declare(strict_types=1);

namespace App\Domains\Content\Demo;

use App\Domains\Community\Events\Events;
use App\Domains\Community\Feed;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Network\Training\Training;
use App\Domains\Platform\Analytics\Shipper;
use App\Domains\Referrals\Closing;
use App\Domains\Referrals\Fees;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use App\Domains\Referrals\Trust\TrustScore;
use App\Support\Sms\LogSmsSender;
use App\Support\Sms\LogWhatsAppSender;
use App\Support\Sms\SmsSender;
use App\Support\Sms\WhatsAppSender;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * A demo workspace with realistic data, built through the real services over the past 90 days: teams in three
 * networks (two that allow fees, one that doesn't), referrals at every stage, fees, feed posts and an event; then
 * analytics and trust scores, so every dashboard is populated. Safety: demo accounts use @demo.invalid (a domain that
 * can never receive mail) and get in-app notifications only; client phones are in the range reserved for fiction
 * (555-0100 to 555-0199); while it runs, texts, WhatsApp, push and email are log-only. Refused in production unless
 * forced. Every record is registered, and a reset removes exactly those.
 */
final class DemoWorkspace
{
    public const DOMAIN = 'demo.invalid';

    public const PASSWORD = 'demo-password-2026';

    /** Network => [teams, tier] */
    public const NETWORKS = ['solar-installers-network' => 6, 'residential-agent-alliance' => 4, 'mortgage-lenders-circle' => 4];

    public const NAMES = ['Sunrise', 'Summit', 'Harbor', 'Canyon', 'Prairie', 'Lakeside', 'Redwood', 'Bluebonnet', 'Granite', 'Willow', 'Cedar', 'Maple', 'Juniper', 'Aspen'];

    public static function exists(): bool
    {
        return Schema::hasTable('demo_records') && DB::table('demo_records')->exists();
    }

    /** @return array{teams: int, referrals: int, owner: string} */
    public static function create(bool $force = false): array
    {
        if (app()->environment('production') && ! $force) {
            throw new RuntimeException('The demo workspace is refused in production. Use --force only on a demo install.');
        }
        if (self::exists()) {
            throw new RuntimeException('A demo workspace already exists. Reset it first.');
        }
        // Nothing leaves the system while the demo is built.
        app()->instance(SmsSender::class, new LogSmsSender);
        app()->instance(WhatsAppSender::class, new LogWhatsAppSender);
        app()->instance(PushSender::class, new class implements PushSender
        {
            public function send(User $user, string $title, string $body, ?string $url): int
            {
                return 0;
            }
        });
        config(['mail.default' => 'array']);
        $start = CarbonImmutable::now();
        try {
            return self::build($start);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    /** @return array{teams: int, referrals: int, owner: string} */
    private static function build(CarbonImmutable $now): array
    {
        $at = function (CarbonImmutable $t): void {
            Carbon::setTestNow($t);
            CarbonImmutable::setTestNow($t);
        };
        $at($now->subDays(95));
        $reg = fn (string $type, int $id, ?int $extra = null) => DB::table('demo_records')->insert(['type' => $type, 'ref_id' => $id, 'extra_id' => $extra]);
        $seats = app(Seats::class);
        $training = app(Training::class);
        // Only places taking seats (city and state switched on in Admin → Locations).
        $open = City::query()->where('is_enabled', true)->whereIn('state_id', DB::table('states')->where('is_enabled', true)->select('id'))->orderBy('id')->limit(500)->get();
        $n = 0;
        $person = function (string $first, string $last) use (&$n, $reg): User {
            $n++;
            $u = User::query()->create(['first_name' => $first, 'last_name' => $last, 'email' => 'demo'.$n.'@'.self::DOMAIN, 'password' => bcrypt(self::PASSWORD), 'phone' => null]);
            $u->forceFill(['email_verified_at' => now()])->save();
            $reg('user', $u->id);

            return $u;
        };
        $owner = $person('Demo', 'Owner');
        $office = Team::forLeader($owner); // the portal needs a team
        $office->update(['name' => 'Demo network office']);
        $reg('team', $office->id);
        $teams = [];
        $nameIdx = 0;
        foreach (self::NETWORKS as $slug => $count) {
            $g = Group::query()->where('slug', $slug)->first();
            if ($g === null) {
                continue;
            }
            $g->owners()->syncWithoutDetaching([$owner->id]);
            $reg('owner', $owner->id, $g->id);
            // One city per distinct seat territory (a network may seat by city, county or state).
            $cities = $open->unique(fn (City $c) => rescue(fn () => implode(':', array_slice(\App\Domains\Network\Seats\Territory::fromCity($g->industry->territory_unit, $c), 0, 2)), 'x'.$c->id, false))
                ->reject(fn (City $c) => DB::table('seats')->where('group_id', $g->id)->whereNotNull('primary_team_id')->where('territory_id', $c->id)->exists())->values();
            $count = min($count, $cities->count());
            $module = $training->module($g->industry);
            $answers = array_column((array) json_decode((string) $module->questions, true), 'answer');
            for ($i = 0; $i < $count; $i++) {
                $brand = self::NAMES[$nameIdx++ % count(self::NAMES)];
                $leader = $person($brand, 'Lead');
                $team = Team::forLeader($leader);
                $team->update(['name' => $brand.' '.explode(' ', $g->industry->name)[0]]);
                $reg('team', $team->id);
                /** @var City $city */
                $city = $cities[$i % $cities->count()];
                // A seat may already exist for this city (seats are found or created per city): remember whether the demo
                // created it, so a reset deletes only demo-created seats and releases any that existed before.
                $before = DB::table('seats')->where('group_id', $g->id)->pluck('id')->all();
                $seats->approve($seats->apply($g, $team, $city, 'DEMO-'.$team->id, null), null);
                $seatId = (int) DB::table('seats')->where(['group_id' => $g->id, 'primary_team_id' => $team->id])->value('id');
                $reg('seat', $seatId, in_array($seatId, $before, true) ? 0 : 1);
                DB::table('team_licenses')->insert(['team_id' => $team->id, 'industry_id' => $g->industry_id, 'state_id' => $city->state_id,
                    'number' => 'DEMO-'.$team->id, 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
                $training->submit($leader, $module, $answers);
                $teams[$slug][] = $team;
            }
            if ($g->industry->tier->value === 'A') {
                app(Fees::class)->setGroupDefault($g, $owner, 'percent', 25, 10);
            }
        }
        // Referrals across the last 90 days, at every stage.
        $refCount = 0;
        $c = 0;
        foreach ($teams as $slug => $list) {
            $g = Group::query()->where('slug', $slug)->firstOrFail();
            $form = collect(app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'])
                ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
                    'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Interested; prefers a call in the evening.'
                }])->all();
            $total = count($list) * 4;
            for ($k = 0; $k < $total; $k++) {
                $from = $list[$k % count($list)];
                $to = $list[($k + 1 + intdiv($k, count($list))) % count($list)];
                if ($from->id === $to->id) {
                    continue;
                }
                $daysAgo = 88 - (int) round($k * 85 / max(1, $total - 1));
                $at($now->subDays($daysAgo)->setTime(10, 0));
                $c++;
                $city = City::query()->findOrFail((int) DB::table('seats')->where(['group_id' => $g->id, 'primary_team_id' => $to->id])->value('territory_id'));
                $ref = app(Referrals::class)->send($from->leader, $g, $city, [...$form, 'client_name' => self::client($c), 'client_phone' => '+1435555'.str_pad((string) (100 + $c % 100), 4, '0', STR_PAD_LEFT),
                    'client_email' => 'client'.$c.'@'.self::DOMAIN], true, null, true)['referral'] ?? null;
                if (! $ref instanceof Referral) {
                    continue; // stopped by a check (e.g. a duplicate): skip it
                }
                $reg('referral', $ref->id);
                $fresh = fn (): Referral => Referral::query()->findOrFail($ref->id);
                $refCount++;
                $fate = $k % 10; // 0: still waiting (recent only), 1: declined, else accepted
                if ($fate === 0 && $daysAgo < 3) {
                    continue;
                }
                $at($now->subDays($daysAgo)->setTime(12, 30));
                if ($fate === 1) {
                    app(Referrals::class)->decline($fresh(), $to->leader, 'Fully booked this month');

                    continue;
                }
                app(Referrals::class)->accept($fresh(), $to->leader);
                $stages = array_column(app(Pipeline::class)->stages($fresh()), 'key');
                if ($daysAgo > 20 && in_array($fate, [2, 3, 4, 5], true)) {
                    $at($now->subDays($daysAgo - 12));
                    app(Pipeline::class)->move($fresh(), $to->leader, 'won', null, ['closing_date' => now()->toDateString(), 'closing_value' => 25000 + 5000 * ($c % 7), 'earnings' => 2500 + 500 * ($c % 7)]);
                    $at($now->subDays($daysAgo - 14));
                    app(Closing::class)->confirm($fresh(), $from->leader);
                    // Older fees have been paid; the newest are still due.
                    if ($daysAgo > 45) {
                        DB::table('referral_fees')->where('referral_id', $ref->id)->update(['status' => 'received', 'paid_at' => now()->addDays(3), 'received_at' => now()->addDays(4), 'updated_at' => now()->addDays(4)]);
                    }
                } elseif ($daysAgo > 20 && $fate === 6) {
                    $at($now->subDays($daysAgo - 10));
                    app(Pipeline::class)->move($fresh(), $to->leader, 'lost', null, ['lost_reason' => 'Client decided to wait until next year']);
                } elseif (isset($stages[1]) && ! in_array($stages[1], ['won', 'lost'], true)) {
                    app(Pipeline::class)->move($fresh(), $to->leader, $stages[1], 'Spoke with the client; next step booked.');
                }
            }
            // Community: a welcome announcement, posts and an upcoming event.
            $at($now->subDays(30));
            $reg('post', app(Feed::class)->post($g, $owner, 'announcement', 'Welcome to the '.$g->name.'! Keep updates flowing: every update helps the referring team.'));
            foreach (array_slice($list, 0, 2) as $t) {
                $reg('post', app(Feed::class)->post($g, $t->leader, 'post', 'Just closed a referral from another city. Thanks for the smooth hand-off!'));
            }
            $at($now->subDays(2));
            $reg('event', app(Events::class)->create($g, $owner, ['title' => $g->name.' monthly meetup', 'description' => 'Share wins and meet new members.', 'kind' => 'virtual', 'location' => null,
                'video_url' => 'https://meet.example.org/'.$g->slug, 'starts_at' => $now->addDays(7)->setTime(17, 0)->toDateTimeString(), 'minutes' => 45, 'timezone' => 'America/Chicago', 'capacity' => null, 'repeat' => null, 'count' => null]));
        }
        $at($now);
        app(Shipper::class)->run();
        TrustScore::recomputeAll(array_values(array_map('intval', DB::table('demo_records')->where('type', 'team')->pluck('ref_id')->all()))); // demo teams only

        return ['teams' => count($teams, COUNT_RECURSIVE) - count($teams), 'referrals' => $refCount, 'owner' => (string) $owner->email];
    }

    /** Remove exactly the demo records (and anything that hangs off them). Returns how many demo records were removed. */
    public static function reset(): int
    {
        if (! self::exists()) {
            return 0;
        }
        $ids = fn (string $type) => DB::table('demo_records')->where('type', $type)->pluck('ref_id')->all();
        [$users, $teams, $refs, $posts, $events] = [$ids('user'), $ids('team'), $ids('referral'), $ids('post'), $ids('event')];
        // Only seats the demo created are deleted; seats that existed before are released, never deleted.
        $seats = DB::table('demo_records')->where(['type' => 'seat', 'extra_id' => 1])->pluck('ref_id')->all();
        $existing = DB::table('demo_records')->where(['type' => 'seat', 'extra_id' => 0])->pluck('ref_id')->all();
        $n = DB::table('demo_records')->count();
        DB::transaction(function () use ($users, $teams, $refs, $seats, $existing, $posts, $events) {
            $tables = array_map(fn ($t) => (string) $t['name'], Schema::getTables());
            $delete = function (array $columns, array $values, array $skip = []) use ($tables) {
                if ($values === []) {
                    return;
                }
                foreach ($tables as $t) {
                    if (in_array($t, [...$skip, 'demo_records', 'migrations'], true)) {
                        continue;
                    }
                    $cols = Schema::getColumnListing($t);
                    foreach ($columns as $c) {
                        if (in_array($c, $cols, true)) {
                            DB::table($t)->whereIn($c, $values)->delete();
                        }
                    }
                }
            };
            $delete(['referral_id'], $refs, ['referrals']);
            DB::table('referrals')->whereIn('id', $refs)->delete();
            $delete(['post_id'], $posts, ['feed_posts']);
            DB::table('feed_posts')->whereIn('id', $posts)->delete();
            $delete(['event_id'], $events, ['events']);
            DB::table('events')->whereIn('id', $events)->delete();
            $delete(['seat_id'], $seats, ['seats']);
            DB::table('seats')->whereIn('id', $seats)->delete();
            DB::table('seats')->whereIn('id', $existing)->whereIn('primary_team_id', $teams)->update(['primary_team_id' => null, 'status' => 'open', 'updated_at' => now()]);
            DB::table('seats')->whereIn('backup_team_id', $teams)->update(['backup_team_id' => null]);
            $delete(['team_id', 'sender_team_id', 'receiver_team_id', 'payer_team_id', 'payee_team_id'], $teams, ['teams', 'seats']);
            DB::table('teams')->whereIn('id', $teams)->delete();
            $delete(['user_id', 'owner_id', 'leader_id', 'created_by', 'actor_id', 'causer_id'], $users, ['users']);
            DB::table('users')->whereIn('id', $users)->delete();
            DB::table('demo_records')->delete();
        });

        return $n;
    }

    private static function client(int $i): string
    {
        $first = ['Maria', 'James', 'Priya', 'Daniel', 'Aisha', 'Tom', 'Elena', 'Marcus', 'Grace', 'Luis', 'Hannah', 'Omar'];
        $last = ['Lopez', 'Carter', 'Shah', 'Nguyen', 'Bello', 'Brennan', 'Rossi', 'Reed', 'Kim', 'Ortiz', 'Fischer', 'Haddad'];

        return $first[$i % count($first)].' '.$last[intdiv($i, count($first)) % count($last)];
    }
}
