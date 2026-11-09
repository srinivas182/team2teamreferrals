<?php

declare(strict_types=1);

namespace App\Domains\Network\Seats\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Members apply for seats and answer seat offers; owners and staff decide applications. */
final class SeatController
{
    public function __construct(private readonly Seats $seats) {}

    public function applyForm(Group $group): Response
    {
        abort_if($group->is_private, 404);

        return Inertia::render('app/groups/Apply', [
            'group' => ['slug' => $group->slug, 'name' => $group->name, 'visibility' => $group->visibility, 'industry' => $group->industry->name, 'territory' => $group->industry->territory_unit,
                'licenseRequired' => $group->industry->license_required, 'terms' => $group->industry->terms(), 'pricing' => $group->pricing],
            'seo' => SeoData::private('Apply to '.$group->name)->toArray(),
        ]);
    }

    public function apply(Request $request, Group $group): RedirectResponse
    {
        abort_if($group->is_private, 404);
        $data = $request->validate(['city_id' => ['required', 'integer', 'exists:cities,id'], 'license_number' => ['nullable', 'string', 'max:60']]);
        $team = Team::forLeader($this->user($request));
        $this->seats->apply($group, $team, City::query()->findOrFail((int) $data['city_id']), $data['license_number'] ?? null, $this->user($request));

        return redirect()->to('/app')->with('status', 'seat-applied');
    }

    public function offer(Request $request, int $offer): Response
    {
        $row = DB::table('seat_offers')->join('seats', 'seats.id', '=', 'seat_offers.seat_id')->join('groups', 'groups.id', '=', 'seats.group_id')
            ->where('seat_offers.id', $offer)->where('seat_offers.team_id', Team::forLeader($this->user($request))->id)
            ->first(['seat_offers.id', 'seat_offers.status', 'seat_offers.expires_at', 'seats.territory_name', 'groups.name as group_name', 'groups.pricing']);
        abort_if($row === null, 404);

        return Inertia::render('app/seats/Offer', [
            'offer' => ['id' => (int) $row->id, 'status' => (string) $row->status, 'expiresAt' => (string) $row->expires_at, 'territory' => (string) $row->territory_name, 'group' => (string) $row->group_name, 'pricing' => json_decode((string) $row->pricing, true)],
            'seo' => SeoData::private('Seat offer')->toArray(),
        ]);
    }

    public function accept(Request $request, int $offer): RedirectResponse
    {
        $this->seats->acceptOffer($offer, Team::forLeader($this->user($request)));

        return redirect()->to('/app')->with('status', 'seat-claimed');
    }

    public function decline(Request $request, int $offer): RedirectResponse
    {
        $this->seats->declineOffer($offer, Team::forLeader($this->user($request)));

        return redirect()->to('/app')->with('status', 'seat-offer-declined');
    }

    /** The applications queue, for group owners and staff who manage groups. */
    public function applications(Request $request, Group $group): Response
    {
        $this->authorizeDecider($request, $group);

        return Inertia::render('admin/groups/Applications', [
            'group' => ['slug' => $group->slug, 'name' => $group->name],
            'basePath' => $request->is('admin/*') ? "/admin/groups/{$group->slug}/applications" : "/app/group/{$group->slug}/applications",
            'applications' => DB::table('seat_applications')->join('teams', 'teams.id', '=', 'seat_applications.team_id')->join('seats', 'seats.id', '=', 'seat_applications.seat_id')
                ->where('seat_applications.group_id', $group->id)->orderByRaw("case seat_applications.status when 'pending' then 0 else 1 end")->orderByDesc('seat_applications.created_at')->limit(200)
                ->get(['seat_applications.id', 'seat_applications.status', 'seat_applications.outcome', 'seat_applications.license_number', 'seat_applications.decision_reason', 'seat_applications.created_at', 'teams.name as team', 'seats.territory_name', 'seats.primary_team_id', 'seats.backup_team_id'])
                ->map(fn ($a) => ['id' => (int) $a->id, 'status' => (string) $a->status, 'outcome' => $a->outcome, 'license' => $a->license_number, 'reason' => $a->decision_reason, 'when' => (string) $a->created_at, 'team' => (string) $a->team, 'territory' => (string) $a->territory_name,
                    'wouldBe' => $a->primary_team_id === null ? 'primary' : ($a->backup_team_id === null ? 'backup' : 'waitlist')]),
            'status' => session('status'),
            'seo' => SeoData::private('Applications · '.$group->name)->toArray(),
        ]);
    }

    public function approve(Request $request, Group $group, int $application): RedirectResponse
    {
        $this->authorizeDecider($request, $group);
        abort_unless(DB::table('seat_applications')->where(['id' => $application, 'group_id' => $group->id])->exists(), 404);
        $outcome = $this->seats->approve($application, $this->user($request));

        return back()->with('status', 'application-approved-'.$outcome);
    }

    public function declineApplication(Request $request, Group $group, int $application): RedirectResponse
    {
        $this->authorizeDecider($request, $group);
        abort_unless(DB::table('seat_applications')->where(['id' => $application, 'group_id' => $group->id])->exists(), 404);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tell the team why, so they can fix it or try another group.'])['reason'];
        $this->seats->decline($application, $this->user($request), $reason);

        return back()->with('status', 'application-declined');
    }

    private function authorizeDecider(Request $request, Group $group): void
    {
        $u = $this->user($request);
        abort_unless($group->isOwnedBy($u) || ($u->isStaff() && $u->can('groups.manage') && $u->hasTwoFactorEnabled()), 403);
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
