<?php

declare(strict_types=1);

namespace App\Domains\Content\Http;

use App\Domains\Content\Industry\IndustryContent;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use App\Support\FormGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Public industry hubs (/industries and /industries/{slug}) and their "Suggest a correction" form. */
final class IndustryHubController
{
    public function index(): Response
    {
        $hubs = IndustryContent::published()->map(fn (Industry $i) => ['slug' => $i->slug, 'name' => $i->name, 'family' => Industry::FAMILIES[$i->family] ?? $i->family,
            'summary' => IndustryContent::page($i)['content']['summary']])->values();
        $summary = 'Guides to the industries on '.config('seo.site_name').': what each covers, typical costs, licensing, and how referrals and fees work, reviewed by named experts.';

        return Inertia::render('Industries', [
            'hubs' => $hubs, 'summary' => $summary,
            'seo' => (new SeoData(title: 'Industries · '.config('seo.site_name'), description: $summary, path: '/industries',
                schema: [Schema::organization(), Schema::breadcrumbs([['Home', '/'], ['Industries', '/industries']])]))->toArray(),
        ]);
    }

    public function show(string $slug): Response
    {
        $industry = Industry::query()->where('slug', $slug)->firstOrFail();
        abort_unless(IndustryContent::record($industry)?->status === 'published', 404);

        return $this->render($industry, false);
    }

    /** Staff preview of a draft, never indexed. */
    public function preview(string $slug): Response
    {
        $industry = Industry::query()->where('slug', $slug)->firstOrFail();
        abort_if(IndustryContent::record($industry) === null, 404);

        return $this->render($industry, true);
    }

    public function correction(Request $request, string $slug, Notifier $notifier): RedirectResponse
    {
        $industry = Industry::query()->where('slug', $slug)->firstOrFail();
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:190'], 'message' => ['required', 'string', 'min:20', 'max:4000'], 'started' => ['required', 'string']]);
        FormGuard::check($request, 'message');
        DB::table('contact_messages')->insert(['name' => $d['name'], 'email' => strtolower((string) $d['email']), 'topic' => 'correction', 'page' => '/industries/'.$industry->slug,
            'message' => $d['message'], 'ip_hash' => FormGuard::ipHash($request), 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        $staff = User::permission('content.manage')->get()->merge(User::role(Permissions::SUPER_ADMIN)->get())->unique('id');
        foreach ($staff as $u) {
            $notifier->send($u, 'admin.contact', ['headline' => 'Correction suggested: '.$industry->name, 'body' => $d['name'].' suggested a correction to the '.$industry->name.' page.'], url('/admin/content/messages'));
        }

        return back()->with('status', 'correction-sent');
    }

    private function render(Industry $industry, bool $preview): Response
    {
        $page = IndustryContent::page($industry);

        return Inertia::render('IndustryHub', [
            ...$page, 'preview' => $preview, 'formStarted' => FormGuard::token(), 'status' => session('status'),
            // Where to find a verified pro: states with a filled seat in this industry's public groups.
            'states' => DB::table('seats')->join('groups', 'groups.id', '=', 'seats.group_id')->join('states', 'states.code', '=', 'seats.state_code')
                ->where(['groups.industry_id' => $industry->id, 'groups.status' => 'live', 'groups.is_private' => false])->whereIn('seats.status', \App\Domains\Network\Directory\Directory::FILLED)
                ->whereNotNull('seats.primary_team_id')->distinct()->orderBy('states.name')->get(['states.code', 'states.name'])->map(fn ($r) => ['code' => strtolower((string) $r->code), 'name' => (string) $r->name])->values(),
            'seo' => (new SeoData(title: $industry->name.': costs, licensing and referrals · '.config('seo.site_name'), description: $page['content']['summary'],
                path: '/industries/'.$industry->slug, index: ! $preview, type: 'website', schema: IndustryContent::schema($industry, $page), modifiedAt: $page['updatedOn']))->toArray(),
        ]);
    }
}
