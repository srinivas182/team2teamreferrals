<?php

declare(strict_types=1);

namespace App\Domains\Support\Ally;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Partners\Partner;
use App\Domains\Partners\PartnerListing;
use Illuminate\Support\Facades\DB;

/**
 * Who is asking, and the facts about their own account Ally may use: read-only, limited to what that person can
 * already see in their account, and with no client names or contact details. Each fact comes with links to the
 * page where the person can act on it.
 */
final class AllyContext
{
    /** @param list<array{label: string, href: string}> $links */
    private function __construct(public readonly string $audience, public readonly ?User $user, public readonly ?Partner $partner, public readonly string $facts, public readonly array $links) {}

    public static function for(?User $user, ?Partner $partner): self
    {
        if ($user !== null) {
            return self::member($user);
        }
        if ($partner !== null) {
            return self::partner($partner);
        }

        return new self('visitor', null, null, '', []);
    }

    /** Ally's audience setting this person falls under. */
    public function setting(): string
    {
        return in_array($this->audience, ['member', 'owner', 'staff'], true) ? 'member' : $this->audience;
    }

    private static function member(User $user): self
    {
        $audience = $user->isStaff() ? 'staff' : (DB::table('group_owners')->where('user_id', $user->id)->exists() ? 'owner' : 'member');
        $team = Team::of($user);
        $f = ["Name: {$user->first_name}."];
        $links = [];
        if ($team === null) {
            $f[] = 'They are not in a team yet, and hold no seat.';

            return new self($audience, $user, null, implode("\n", $f), [['label' => 'Find a network', 'href' => '/groups']]);
        }
        $f[] = "Team: {$team->name} (their role: ".($team->roleOf($user) ?? 'member').').';
        $seats = DB::table('seats as s')->join('groups as g', 'g.id', '=', 's.group_id')->where('s.primary_team_id', $team->id)->get(['s.territory_name', 's.status', 's.trial_ends_at', 'g.name']);
        $f[] = $seats->isEmpty() ? 'Seats: none yet.' : 'Seats: '.$seats->map(fn ($s) => "{$s->territory_name} in {$s->name} ({$s->status}".($s->status === 'trial' && $s->trial_ends_at ? ', free trial ends '.substr((string) $s->trial_ends_at, 0, 10) : '').')')->implode('; ').'.';
        $waiting = DB::table('referrals as r')->join('groups as g', 'g.id', '=', 'r.group_id')->join('teams as t', 't.id', '=', 'r.sender_team_id')
            ->where('r.receiver_team_id', $team->id)->where('r.status', 'sent')->orderBy('r.respond_by')->limit(5)->get(['r.ulid', 'r.respond_by', 'g.name', 't.name as sender']);
        $f[] = 'Referrals waiting for their answer: '.($waiting->isEmpty() ? 'none' : $waiting->count().' ('.$waiting->map(fn ($r) => "from {$r->sender} in {$r->name}, answer by ".substr((string) $r->respond_by, 0, 16))->implode('; ').')').'.';
        foreach ($waiting as $i => $r) {
            $links[] = ['label' => 'Open referral from '.$r->sender, 'href' => '/app/referrals/'.$r->ulid];
        }
        $sent = DB::table('referrals')->where('sender_team_id', $team->id)->where('created_at', '>=', now()->subDays(90))->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status');
        $f[] = 'Referrals they sent in the last 90 days: '.($sent->isEmpty() ? 'none' : $sent->map(fn ($n, $s) => "{$n} {$s}")->implode(', ')).'.';
        $card = DB::table('billing_customers')->where(['owner_type' => 'team', 'owner_id' => $team->id])->whereNotNull('payment_method_id')->exists();
        $unpaid = DB::table('invoices')->where(['owner_type' => 'team', 'owner_id' => $team->id])->whereIn('status', ['failed', 'action_required'])->get(['number', 'total_cents', 'status']);
        $last = DB::table('invoices')->where(['owner_type' => 'team', 'owner_id' => $team->id])->orderByDesc('id')->first(['number', 'total_cents', 'status', 'period']);
        $f[] = 'Billing: '.($card ? 'a card is saved' : 'no card saved').'; '.($unpaid->isEmpty() ? 'nothing unpaid' : 'unpaid: '.$unpaid->map(fn ($i) => "{$i->number} \$".number_format($i->total_cents / 100, 2)." ({$i->status})")->implode(', '))
            .($last !== null ? "; latest invoice {$last->number} for {$last->period}: \$".number_format($last->total_cents / 100, 2)." ({$last->status})" : '; no invoices yet').'.';
        $links[] = ['label' => 'Billing', 'href' => '/app/billing'];
        $tickets = DB::table('support_tickets')->where('user_id', $user->id)->whereIn('status', ['open', 'pending'])->get(['number', 'status']);
        if ($tickets->isNotEmpty()) {
            $f[] = 'Open support tickets: '.$tickets->map(fn ($t) => "{$t->number} ({$t->status})")->implode(', ').'.';
            $links[] = ['label' => 'Your support tickets', 'href' => '/app/support'];
        }

        return new self($audience, $user, null, implode("\n", $f), $links);
    }

    private static function partner(Partner $p): self
    {
        $listed = PartnerListing::query()->with('city.state')->where('partner_id', $p->id)->get();
        $enquiries = DB::table('partner_enquiries')->where('partner_id', $p->id)->where('created_at', '>=', now()->subDays(30))->count();
        $f = ["Business: {$p->business_name} (partner directory advertiser).", 'Listing: '.(Partner::STATUSES[$p->status] ?? $p->status).'; billing: '.(Partner::BILLING[$p->billing_status] ?? $p->billing_status)
            .($p->trial_ends_at !== null && $p->billing_status === 'trial' ? ', free trial ends '.$p->trial_ends_at->toDateString() : '').($p->paid_until !== null ? ', paid until '.$p->paid_until->toDateString() : '')
            .($p->cancel_at !== null ? ', ends '.$p->cancel_at->toDateString() : '').'.',
            'Cities: '.$listed->map(fn ($l) => $l->city->name.', '.$l->city->state->code.($l->status === 'waitlist' ? ' (waitlist)' : ''))->implode('; ').'.',
            "Enquiries in the last 30 days: {$enquiries}."];

        return new self('partner', null, $p, implode("\n", $f), [['label' => 'Partner account', 'href' => '/partner-portal/'.$p->ulid]]);
    }
}
