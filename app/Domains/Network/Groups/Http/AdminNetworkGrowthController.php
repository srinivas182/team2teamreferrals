<?php

declare(strict_types=1);

namespace App\Domains\Network\Groups\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Groups\Regions;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Overflow demand and "open next group", plus private networks for companies. */
final class AdminNetworkGrowthController
{
    public function overflow(): Response
    {
        $groups = Group::query()->where('is_private', false)->get()->groupBy('industry_id');

        return Inertia::render('admin/groups/Overflow', [
            'rows' => Industry::query()->orderBy('name')->get()->map(function (Industry $i) use ($groups) {
                $mine = $groups->get($i->id) ?? collect();
                $launch = $mine->firstWhere('is_launch', true);

                return [
                    'industry' => ['slug' => $i->slug, 'name' => $i->name, 'tier' => $i->tier->value],
                    'launch' => $launch ? ['slug' => $launch->slug, 'name' => $launch->name] : null,
                    'groups' => $mine->map(fn (Group $g) => ['slug' => $g->slug, 'name' => $g->name, 'region' => $g->region])->values(),
                    'takenRegions' => $mine->pluck('region')->filter()->values(),
                    'waitlisted' => \App\Domains\Network\Seats\SeatMap::waitlisted($i), 'metroFill' => \App\Domains\Network\Seats\SeatMap::metroFill($i),
                ];
            }),
            'regions' => collect(Regions::ALL)->map(fn ($r, $k) => ['key' => $k, 'label' => $r['label'], 'states' => count($r['states'])])->values(),
            'status' => session('status'),
            'seo' => SeoData::private('Overflow demand')->toArray(),
        ]);
    }

    public function openNext(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'industry' => ['required', 'string', 'exists:industries,slug'],
            'region' => ['required', Rule::in([...array_keys(Regions::ALL), 'national'])],
            'name' => ['nullable', 'string', 'max:160'],
        ]);
        $industry = Industry::query()->where('slug', $data['industry'])->firstOrFail();
        $parent = Group::query()->where(['industry_id' => $industry->id, 'is_launch' => true])->first();
        if (! $parent instanceof Group) {
            throw ValidationException::withMessages(['industry' => 'This industry has no launch group to grow from.']);
        }
        $region = $data['region'] === 'national' ? null : (string) $data['region'];
        if ($region !== null && Group::query()->where(['industry_id' => $industry->id, 'region' => $region, 'is_private' => false])->exists()) {
            throw ValidationException::withMessages(['region' => 'This industry already has a group for that region.']);
        }
        $name = trim((string) ($data['name'] ?? '')) ?: $parent->name.($region ? ' — '.Regions::ALL[$region]['label'] : ' II');

        $group = DB::transaction(function () use ($industry, $parent, $region, $name, $request) {
            $g = Group::create([
                'industry_id' => $industry->id, 'slug' => self::uniqueSlug($name), 'name' => $name, 'description' => $parent->description,
                'visibility' => $parent->visibility, 'region' => $region, 'parent_group_id' => $parent->id, 'rules' => $parent->rules, 'pricing' => $parent->pricing,
            ]);
            // Form and pipeline overrides come along; an agreement override comes as an unapproved draft (members sign the industry's approved one meanwhile).
            foreach (DB::table('industry_templates')->where(['scope_type' => 'group', 'scope_id' => $parent->id, 'is_active' => true])->get() as $t) {
                DB::table('industry_templates')->insert(['kind' => $t->kind, 'scope_type' => 'group', 'scope_id' => $g->id, 'version' => 1, 'body' => $t->body, 'is_active' => true, 'created_by' => $request->user()?->getKey(), 'created_at' => now(), 'updated_at' => now()]);
            }
            activity('admin')->causedBy($request->user())->performedOn($g)->event('group-opened')->withProperties(['from' => $parent->slug, 'region' => $region])->log("Opened {$g->name} from {$parent->name}");

            return $g;
        });

        return redirect()->to('/admin/groups/'.$group->slug)->with('status', 'group-opened');
    }

    public function createPrivate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'industry' => ['required', 'string', 'exists:industries,slug'],
            'owner_email' => ['required', 'email'],
        ]);
        $owner = User::query()->where('email', strtolower((string) $data['owner_email']))->first();
        if (! $owner instanceof User) {
            throw ValidationException::withMessages(['owner_email' => 'No account uses this email yet.']);
        }
        $industry = Industry::query()->where('slug', $data['industry'])->firstOrFail();
        $group = Group::create([
            'industry_id' => $industry->id, 'slug' => self::uniqueSlug((string) $data['name']), 'name' => $data['name'], 'is_private' => true, 'visibility' => 'invite_only',
            'rules' => Group::defaultRules(), 'pricing' => Group::defaultPricing($industry),
        ]);
        $group->owners()->attach($owner->id, ['added_by' => $request->user()?->getKey()]);
        activity('admin')->causedBy($request->user())->performedOn($group)->event('private-network-created')->withProperties(['owner' => $owner->ulid])->log("Created private network {$group->name}");

        return redirect()->to('/admin/groups/'.$group->slug)->with('status', 'private-network-created');
    }

    private static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'group';
        $slug = $base;
        for ($n = 2; Group::query()->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
