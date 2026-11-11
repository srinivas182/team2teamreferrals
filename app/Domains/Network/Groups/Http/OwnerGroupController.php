<?php

declare(strict_types=1);

namespace App\Domains\Network\Groups\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\GroupSettings;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Seo\SeoData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** The owner's view of their group: health, rules and upcoming changes. Owners run the group; they never change pricing. */
final class OwnerGroupController
{
    public function __invoke(Request $request, Group $group): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($group->isOwnedBy($user), 403);

        return Inertia::render('app/group/Overview', [
            'group' => ['slug' => $group->slug, 'name' => $group->name, 'description' => $group->description, 'status' => $group->status, 'visibility' => $group->visibility,
                'industry' => $group->industry->name, 'terms' => $group->industry->terms(), 'flaggedTeams' => \App\Domains\Referrals\Credits::flagged($group), 'feeAllowed' => $group->industry->tier === \App\Domains\Network\Industries\Tier::Open, 'feeDefault' => \App\Domains\Referrals\Fees::groupDefault($group), 'feeDueDays' => (int) data_get($group->rules, 'fee_due_days', 10), 'trainingRequired' => \App\Domains\Network\Training\Training::required($group), 'trainingLocked' => $group->industry->training_locked, 'trainingFrom' => \App\Domains\Network\Training\Training::enforcedFrom($group)->toDateString(), 'rules' => $group->rules, 'pricing' => $group->pricing],
            'health' => GroupSettings::health($group),
            'upcoming' => DB::table('group_scheduled_changes')->where('group_id', $group->id)->whereNull('applied_at')->whereNull('cancelled_at')->orderBy('effective_on')->get(['section', 'effective_on'])
                ->map(fn ($c) => ['section' => (string) $c->section, 'effectiveOn' => (string) $c->effective_on]),
            'seo' => SeoData::private($group->name)->toArray(),
        ]);
    }
}
