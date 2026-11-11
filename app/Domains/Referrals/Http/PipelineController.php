<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\PipelineEditor;
use App\Domains\Referrals\Referral;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PipelineController
{
    public function __construct(private readonly Pipeline $pipeline, private readonly PipelineEditor $editor) {}

    /** The board: the team's accepted referrals by stage, one network at a time. */
    public function board(Request $request): Response
    {
        $team = Team::of($this->user($request));
        $refs = $team === null ? collect() : Referral::query()->with(['group.industry', 'seat', 'sender'])->where(['receiver_team_id' => $team->id, 'status' => 'accepted'])->latest('stage_changed_at')->get();
        $groups = $refs->pluck('group')->unique('id')->values();
        $slug = (string) $request->query('group', '');
        $group = $groups->firstWhere('slug', $slug) ?? $groups->first();
        $cards = [];
        if ($group instanceof Group) {
            $updateDays = (int) $group->rule('update_days');
            $firstHours = (int) $group->rule('first_contact_hours');
            foreach ($refs->where('group_id', $group->id) as $r) {
                $stage = $this->pipeline->stage($r);
                $last = $r->last_update_at ?? $r->first_contact_at;
                $flags = array_values(array_filter([
                    $r->first_contact_at === null && $r->closed_at === null ? ($r->accepted_at?->copy()->addHours($firstHours)->isPast() ? 'First contact overdue' : 'Call due') : null,
                    $last !== null && $r->closed_at === null && $last->copy()->addDays($updateDays - 1)->isPast() ? 'Update due' : null,
                    ($stage['limit_days'] ?? null) && $r->closed_at === null && $r->stage_changed_at?->copy()->addDays((int) $stage['limit_days'])->isPast() ? 'Over time in stage' : null,
                ]));
                $team = Team::of($this->user($request));
                $next = $team !== null ? (\App\Domains\Referrals\Assist\NextActions::for($r, $team)[0] ?? null) : null;
                $cards[] = ['id' => $r->ulid, 'stage' => $r->stage, 'client' => $r->client_name, 'territory' => $r->seat->territory_name, 'sender' => $r->sender->name,
                    'days' => (int) ($r->stage_changed_at?->diffInDays(now()) ?? 0), 'flags' => $flags,
                    'score' => \App\Domains\Referrals\Assist\CloseLikelihood::for($r)['score'] ?? null, 'next' => $next !== null ? ['key' => $next['key'], 'title' => $next['title']] : null];
            }
        }

        return Inertia::render('app/Pipeline', [
            'groups' => $groups->map(fn (Group $g) => ['slug' => $g->slug, 'name' => $g->name])->all(),
            'group' => $group?->slug,
            'askEarnings' => $group instanceof Group && (\App\Domains\Referrals\Fees::groupDefault($group)['type'] ?? null) === 'percent',
            'stages' => $group instanceof Group ? $this->pipeline->stages($refs->firstWhere('group_id', $group->id)) : [],
            'cards' => $cards,
            'status' => session('status'),
            'seo' => SeoData::private('Pipeline')->toArray(),
        ]);
    }

    /** Group owners: edit the network's stages. */
    public function edit(Request $request, Group $group): Response
    {
        $user = $this->owner($request, $group);

        return Inertia::render('app/group/Pipeline', [
            'group' => ['slug' => $group->slug, 'name' => $group->name, 'locked' => PipelineEditor::locked($group)],
            'stages' => app(\App\Domains\Network\Industries\Templates\IndustryTemplates::class)->resolve('stages', $group->industry, $group->id)['body'],
            'industryStages' => $this->editor->industryStages($group),
            'counts' => PipelineEditor::counts($group),
            'isAdmin' => $user->can('groups.manage'),
            'status' => session('status'),
            'seo' => SeoData::private('Pipeline · '.$group->name)->toArray(),
        ]);
    }

    public function save(Request $request, Group $group): RedirectResponse
    {
        $user = $this->owner($request, $group);
        $d = $request->validate(['stages' => ['required', 'array', 'min:3', 'max:20'], 'moves' => ['nullable', 'array']]);
        $this->editor->save($group, $user, array_values(array_filter((array) $d['stages'], 'is_array')), array_map('strval', (array) ($d['moves'] ?? [])), $user->can('groups.manage'));

        return back()->with('status', 'pipeline-saved');
    }

    /** Admins lock or unlock a network's pipeline (e.g. for compliance or a company's private network). */
    public function lock(Request $request, Group $group): RedirectResponse
    {
        abort_unless($this->user($request)->can('groups.manage'), 403);
        $locked = $request->boolean('locked');
        $group->forceFill(['rules' => [...$group->rules, 'pipeline_locked' => $locked]])->save();
        activity('admin')->causedBy($request->user())->performedOn($group)->event('pipeline-lock')->withProperties(['locked' => $locked])->log(($locked ? 'Locked' : 'Unlocked').' the pipeline of '.$group->name);

        return back()->with('status', $locked ? 'pipeline-locked' : 'pipeline-unlocked');
    }

    private function owner(Request $request, Group $group): User
    {
        $u = $this->user($request);
        abort_unless($group->isOwnedBy($u) || $u->can('groups.manage'), 403);

        return $u;
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
