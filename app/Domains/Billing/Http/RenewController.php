<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http;

use App\Domains\Billing\BillingAccounts;
use App\Domains\Billing\TeamLock;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renew: what a team with unpaid (inactive) seats sees. The seats and when each would be released, the referrals it
 * missed while inactive, what it owes, and one place to pay (leader or assistant). Members see who to ask.
 */
final class RenewController
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $team = Team::of($user);
        if ($team === null) {
            return redirect()->to('/app');
        }
        $seats = DB::table('seats')->join('groups', 'groups.id', '=', 'seats.group_id')->where('seats.primary_team_id', $team->id)->where('seats.status', 'inactive')
            ->get(['seats.id', 'seats.territory_name', 'seats.grace_started_at', 'groups.name as group', 'groups.rules']);
        $canPay = BillingAccounts::canManage($user, 'team', $team->id);
        $invoices = DB::table('invoices')->where(['owner_type' => 'team', 'owner_id' => $team->id])->whereIn('status', ['failed', 'open'])->orderBy('id')
            ->get(['id', 'number', 'total_cents', 'period', 'failure']);

        return Inertia::render('app/Renew', [
            'locked' => TeamLock::locked($team),
            // Why: unpaid seats, or a charge (a platform fee) left unpaid past its grace days.
            'unpaidCharges' => TeamLock::unpaidCharges($team),
            'team' => $team->name,
            'canPay' => $canPay,
            'leader' => $team->leader->name,
            'seats' => $seats->map(fn ($s) => ['territory' => (string) $s->territory_name, 'group' => (string) $s->group,
                'releasedOn' => $s->grace_started_at ? now()->parse((string) $s->grace_started_at)->addDays((int) (json_decode((string) $s->rules, true)['release_days'] ?? 30))->toFormattedDateString() : null])->values(),
            'missed' => DB::table('missed_referrals as m')->join('seats as s', 's.id', '=', 'm.seat_id')->join('groups as g', 'g.id', '=', 's.group_id')
                ->where('m.team_id', $team->id)->where('m.created_at', '>=', now()->subDays(90))->orderByDesc('m.id')->limit(100)
                ->get(['m.client', 'm.created_at', 's.territory_name', 'g.name as group'])
                ->map(fn ($m) => ['client' => (string) $m->client, 'date' => substr((string) $m->created_at, 0, 10), 'territory' => (string) $m->territory_name, 'group' => (string) $m->group])->values(),
            'invoices' => $canPay ? $invoices->map(fn ($i) => ['id' => (int) $i->id, 'number' => (string) $i->number, 'total' => (int) $i->total_cents, 'period' => (string) $i->period, 'failure' => $i->failure])->values() : [],
            'card' => $canPay ? BillingAccounts::cardFor($team) : null,
            'stripeKey' => $canPay && app(\App\Domains\Platform\Payments\Stripe::class)->connected() ? app(\App\Domains\Platform\Payments\Stripe::class)->publishableKey() : null,
            'status' => session('status'),
            'seo' => SeoData::private('Renew')->toArray(),
        ]);
    }
}
