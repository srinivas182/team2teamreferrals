<?php

declare(strict_types=1);

namespace App\Domains\Network\Groups\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Referrals\Dashboard\ReferralLedger;
use App\Domains\Seo\SeoData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A group's referrals, for its owners and for staff who manage groups: how many, the list (S/N, date, lead, who sent
 * and received it, status, GCI, commission, platform earnings), totals and a CSV for any date range.
 */
final class GroupReferralsController
{
    public const PER_PAGE = 50;

    public function index(Request $request, Group $group): Response
    {
        $user = $this->authorize($request, $group);
        $f = $this->filters($request);
        $q = ReferralLedger::query($f, $group->id);
        $totals = ReferralLedger::totals($q);
        $pages = (int) max(1, ceil($totals['count'] / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->query('page', '1')));

        return Inertia::render('admin/groups/Referrals', [
            'group' => ['slug' => $group->slug, 'name' => $group->name],
            'basePath' => $request->is('admin/*') ? "/admin/groups/{$group->slug}" : "/app/group/{$group->slug}",
            'admin' => $request->is('admin/*'),
            'filters' => $f, 'totals' => $totals, 'page' => $page, 'pages' => $pages, 'stages' => ReferralLedger::STAGES,
            'rows' => ReferralLedger::rows($q, $page, self::PER_PAGE, ReferralLedger::fullNames($user), $totals['count']),
            // "Not a real client" calls the sender asked the owner to review.
            'notRealReviews' => \App\Domains\Referrals\NotRealClients::pending($group),
            // Teams whose sharing reward is paused here, to lift, keep or remove.
            'pausedTeams' => \App\Domains\Referrals\SharingReward::pausedIn($group),
            'seo' => SeoData::private('Referrals · '.$group->name)->toArray(),
        ]);
    }

    public function csv(Request $request, Group $group): StreamedResponse
    {
        $user = $this->authorize($request, $group);
        $f = $this->filters($request);
        $full = ReferralLedger::fullNames($user);
        if ($full) {
            activity('privacy')->causedBy($user)->performedOn($group)->event('referrals-exported')->withProperties(['filters' => $f])->log('Group referrals exported with client names');
        }

        return ReferralLedger::csv(ReferralLedger::query($f, $group->id), $full, $group->slug.'-referrals'.(isset($f['from']) ? '-'.$f['from'] : '').(isset($f['to']) ? '-to-'.$f['to'] : '').'.csv');
    }

    /** Owner or staff: reinstate a "not a real client" call (it was real) or uphold it. */
    public function decideNotReal(Request $request, Group $group, string $referral, \App\Domains\Referrals\NotRealClients $notReal): \Illuminate\Http\RedirectResponse
    {
        $user = $this->authorize($request, $group);
        $ref = \App\Domains\Referrals\Referral::query()->where('ulid', $referral)->firstOrFail();
        $notReal->decide($ref, $group, $user, $request->validate(['decision' => ['required', 'in:reinstate,uphold']])['decision'] === 'reinstate');

        return back()->with('status', 'not-real-decided');
    }

    /** Lift, keep or remove for a team whose sharing reward is paused in this group. */
    public function sharingPause(Request $request, Group $group, int $team, \App\Domains\Referrals\NotRealClients $notReal): \Illuminate\Http\RedirectResponse
    {
        $user = $this->authorize($request, $group);
        $t = \App\Domains\Network\Seats\Models\Team::query()->findOrFail($team);
        $d = $request->validate(['action' => ['required', 'in:lift,keep,remove'], 'reason' => ['required_if:action,lift,remove', 'nullable', 'string', 'min:5', 'max:500']],
            ['reason.required_if' => 'Add a short reason (it’s kept in the activity log).']);
        $action = (string) $d['action'];
        $reason = (string) ($d['reason'] ?? '');
        if ($action === 'lift') {
            $notReal->lift($group, $t, $user, $reason);
        } elseif ($action === 'keep') {
            $notReal->keep($group, $t, $user);
        } else {
            abort_if($notReal->remove($group, $t, $user, $reason) === 0, 422, 'This team holds no seat in this group.');
        }

        return back()->with('status', "sharing-pause-{$action}");
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        return collect(['stage', 'from', 'to', 'city', 'sender', 'receiver'])->mapWithKeys(fn ($k) => [$k => mb_substr(trim((string) $request->query($k, '')), 0, 80)])->filter(fn ($v) => $v !== '')
            ->filter(fn ($v, $k) => ! in_array($k, ['from', 'to'], true) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1)->all();
    }

    /** Owners of the group, or staff who manage groups (with two-factor when required). */
    private function authorize(Request $request, Group $group): User
    {
        $u = $request->user();
        abort_unless($u instanceof User && ($group->isOwnedBy($u) || ($u->isStaff() && $u->can('groups.manage') && (! \App\Domains\Identity\StaffTwoFactor::required() || $u->hasTwoFactorEnabled()))), 403);

        return $u;
    }
}
