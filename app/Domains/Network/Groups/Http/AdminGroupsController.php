<?php

declare(strict_types=1);

namespace App\Domains\Network\Groups\Http;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Seo\SeoData;
use Inertia\Inertia;
use Inertia\Response;

/** Read-only list of groups with their rules and pricing, for reviewing the launch set. Editing arrives in Sprint 10. */
final class AdminGroupsController
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/groups/Index', [
            'groups' => Group::query()->with('industry:id,slug,name,tier')->orderBy('name')->get()->map(fn (Group $g) => [
                'slug' => $g->slug, 'name' => $g->name, 'status' => $g->status, 'launch' => $g->is_launch,
                'industry' => ['slug' => $g->industry?->slug, 'name' => $g->industry?->name, 'tier' => $g->industry?->tier->value],
                'pricing' => $g->pricing, 'rules' => $g->rules,
            ]),
            'seo' => SeoData::private('Groups')->toArray(),
        ]);
    }
}
