<?php

declare(strict_types=1);

namespace App\Domains\Network\Groups\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\GroupSettings;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Groups: list, and one group's details, pricing and rules (now or scheduled), owners and health. */
final class AdminGroupsController
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/groups/Index', [
            'groups' => Group::query()->with('industry:id,slug,name,tier')->orderBy('name')->get()->map(fn (Group $g) => [
                'slug' => $g->slug, 'name' => $g->name, 'status' => $g->status, 'launch' => $g->is_launch, 'private' => $g->is_private, 'region' => $g->region,
                'industry' => ['slug' => $g->industry->slug, 'name' => $g->industry->name, 'tier' => $g->industry->tier->value],
                'pricing' => $g->pricing, 'rules' => $g->rules,
            ]),
            'industries' => Industry::query()->orderBy('name')->get(['slug', 'name'])->map(fn (Industry $i) => ['slug' => $i->slug, 'name' => $i->name]),
            'status' => session('status'),
            'seo' => SeoData::private('Groups')->toArray(),
        ]);
    }

    public function show(Group $group): Response
    {
        $group->load('industry');

        return Inertia::render('admin/groups/Show', [
            'group' => [
                'slug' => $group->slug, 'name' => $group->name, 'description' => $group->description, 'status' => $group->status, 'visibility' => $group->visibility,
                'photo' => $group->photo_path ? Storage::disk('public')->url($group->photo_path) : null, 'launch' => $group->is_launch, 'private' => $group->is_private, 'region' => $group->region,
                'pricing' => $group->pricing, 'rules' => $group->rules,
                'industry' => ['slug' => $group->industry->slug, 'name' => $group->industry->name, 'tier' => $group->industry->tier->value, 'guaranteeAllowed' => $group->industry->tier->allowsValueGuarantee(), 'terms' => $group->industry->terms()],
            ],
            'owners' => $group->owners()->orderBy('first_name')->get()->map(fn (User $u) => ['id' => $u->ulid, 'name' => $u->name, 'email' => $u->email]),
            'scheduled' => DB::table('group_scheduled_changes')->where('group_id', $group->id)->whereNull('applied_at')->whereNull('cancelled_at')->orderBy('effective_on')->get()
                ->map(fn ($c) => ['id' => (int) $c->id, 'section' => (string) $c->section, 'values' => json_decode((string) $c->values, true), 'effectiveOn' => (string) $c->effective_on, 'notified' => $c->notified_at !== null]),
            'overrides' => collect(['form' => 'Referral form', 'stages' => 'Pipeline', 'agreement' => 'Agreement'])->map(fn ($label, $kind) => [
                'kind' => $kind, 'label' => $label,
                'version' => DB::table('industry_templates')->where(['kind' => $kind, 'scope_type' => 'group', 'scope_id' => $group->id, 'is_active' => true])->value('version'),
            ])->values(),
            'affected' => GroupSettings::affected($group),
            'health' => GroupSettings::health($group),
            'status' => session('status'),
            'seo' => SeoData::private($group->name)->toArray(),
        ]);
    }

    public function updateDetails(Request $request, Group $group): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:draft,live,closed'],
            'visibility' => ['required', 'in:open,invite_only'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        if ($data['status'] === 'live' && ! $group->industry->isLive()) {
            throw ValidationException::withMessages(['status' => 'The industry has to be live (compliance approved) before its groups can go live.']);
        }
        $before = $group->only(['name', 'description', 'status', 'visibility']);
        if ($request->hasFile('photo')) {
            $group->photo_path = $request->file('photo')?->store('groups', 'public') ?: $group->photo_path;
        }
        unset($data['photo']);
        $group->fill($data)->save();
        activity('admin')->causedBy($request->user())->performedOn($group)->event('group-updated')->withProperties(['old' => $before, 'attributes' => $group->only(['name', 'description', 'status', 'visibility'])])->log("Updated {$group->name}");

        return back()->with('status', 'group-saved');
    }

    /** Pricing or rules, applied now or scheduled for a future date (members are told 30 days ahead). */
    public function updateSettings(Request $request, Group $group, string $section): RedirectResponse
    {
        abort_unless(in_array($section, ['pricing', 'rules'], true), 404);
        $timing = $request->validate(['when' => ['required', 'in:now,scheduled'], 'effective_on' => ['required_if:when,scheduled', 'nullable', 'date', 'after:today']]);
        $values = $section === 'pricing' ? GroupSettings::pricing($group, (array) $request->input('values')) : GroupSettings::rules((array) $request->input('values'));

        if ($timing['when'] === 'scheduled') {
            DB::table('group_scheduled_changes')->insert(['group_id' => $group->id, 'section' => $section, 'values' => json_encode($values), 'effective_on' => $timing['effective_on'], 'created_by' => $request->user()?->getKey(), 'created_at' => now(), 'updated_at' => now()]);
            activity('admin')->causedBy($request->user())->performedOn($group)->event('group-change-scheduled')->withProperties(['section' => $section, 'effective_on' => $timing['effective_on'], 'attributes' => $values])->log("Scheduled a {$section} change for {$group->name}");

            return back()->with('status', 'group-change-scheduled');
        }
        $before = $group->{$section};
        $group->forceFill([$section => $values])->save();
        activity('admin')->causedBy($request->user())->performedOn($group)->event('group-'.$section.'-changed')->withProperties(['old' => $before, 'attributes' => $values])->log("Changed {$group->name} {$section}");

        return back()->with('status', 'group-saved');
    }

    public function cancelScheduled(Request $request, Group $group, int $change): RedirectResponse
    {
        $n = DB::table('group_scheduled_changes')->where(['id' => $change, 'group_id' => $group->id])->whereNull('applied_at')->update(['cancelled_at' => now()]);
        abort_if($n === 0, 404);
        activity('admin')->causedBy($request->user())->performedOn($group)->event('group-change-cancelled')->withProperties(['change' => $change])->log("Cancelled a scheduled change for {$group->name}");

        return back()->with('status', 'group-change-cancelled');
    }

    public function addOwner(Request $request, Group $group): RedirectResponse
    {
        $email = strtolower((string) $request->validate(['email' => ['required', 'email']])['email']);
        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['email' => 'No account uses this email yet. Ask them to sign up first; inviting new owners by email arrives with onboarding.']);
        }
        $group->owners()->syncWithoutDetaching([$user->id => ['added_by' => $request->user()?->getKey()]]);
        activity('admin')->causedBy($request->user())->performedOn($group)->event('group-owner-added')->withProperties(['owner' => $user->ulid])->log("Made {$user->name} an owner of {$group->name}");

        return back()->with('status', 'group-owner-added');
    }

    public function removeOwner(Request $request, Group $group, User $user): RedirectResponse
    {
        $group->owners()->detach($user->id);
        activity('admin')->causedBy($request->user())->performedOn($group)->event('group-owner-removed')->withProperties(['owner' => $user->ulid])->log("Removed {$user->name} as owner of {$group->name}");

        return back()->with('status', 'group-owner-removed');
    }
}
