<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Referrals\Disputes;
use App\Domains\Referrals\Referral;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** The dispute centre: group owners (and admins) decide close and fee disputes. */
final class DisputeController
{
    public function __construct(private readonly Disputes $disputes) {}

    public function index(Request $request, Group $group): Response
    {
        $this->owner($request, $group);

        return Inertia::render('app/group/Disputes', [
            'group' => ['slug' => $group->slug, 'name' => $group->name],
            ...Disputes::forGroup($group),
            'status' => session('status'),
            'seo' => SeoData::private('Disputes · '.$group->name)->toArray(),
        ]);
    }

    public function disputeFee(Request $request, int $fee): RedirectResponse
    {
        $this->disputes->disputeFee($fee, $this->user($request), (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason']);

        return back()->with('status', 'fee-disputed');
    }

    public function resolveClose(Request $request, Referral $referral): RedirectResponse
    {
        $this->owner($request, $referral->group);
        $d = $request->validate(['confirm' => ['required', 'boolean'], 'note' => ['required', 'string', 'max:1000']], ['note.required' => 'Explain the decision; both teams see it.']);
        $this->disputes->resolveClose($referral, $this->user($request), (bool) $d['confirm'], (string) $d['note']);

        return back()->with('status', 'dispute-decided');
    }

    public function resolveFee(Request $request, int $fee): RedirectResponse
    {
        $groupId = (int) DB::table('referral_fees as f')->join('referrals as r', 'r.id', '=', 'f.referral_id')->where('f.id', $fee)->value('r.group_id');
        $this->owner($request, Group::query()->findOrFail($groupId));
        $d = $request->validate(['decision' => ['required', 'in:confirmed,adjusted,waived'], 'note' => ['required', 'string', 'max:1000'], 'amount' => ['nullable', 'required_if:decision,adjusted', 'numeric', 'min:0']],
            ['note.required' => 'Explain the decision; both teams see it.']);
        $this->disputes->resolveFee($fee, $this->user($request), (string) $d['decision'], (string) $d['note'], isset($d['amount']) ? (float) $d['amount'] : null);

        return back()->with('status', 'dispute-decided');
    }

    private function owner(Request $request, Group $group): void
    {
        $u = $this->user($request);
        abort_unless($group->isOwnedBy($u) || $u->can('groups.manage'), 403);
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
