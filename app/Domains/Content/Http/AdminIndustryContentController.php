<?php

declare(strict_types=1);

namespace App\Domains\Content\Http;

use App\Domains\Content\Industry\IndustryContent;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Website → Industries: write, review and publish each industry's hub. */
final class AdminIndustryContentController
{
    public function index(): Response
    {
        $records = DB::table('industry_content')->get(['industry_id', 'status', 'reviewer', 'reviewed_on', 'updated_at'])->keyBy('industry_id');
        $rows = Industry::query()->orderBy('name')->get()->map(function (Industry $i) use ($records) {
            $r = $records->get($i->id);

            return ['slug' => $i->slug, 'name' => $i->name, 'tier' => $i->tier->value,
                'state' => $r === null ? 'none' : ($r->status === 'published' ? 'published' : ($r->reviewer ? 'ready' : 'review_pending')),
                'reviewer' => $r?->reviewer, 'reviewedOn' => $r?->reviewed_on, 'updatedOn' => $r !== null ? substr((string) $r->updated_at, 0, 10) : null];
        })->values();

        return Inertia::render('admin/content/Industries', ['industries' => $rows, 'status' => session('status'), 'seo' => SeoData::private('Industry content')->toArray()]);
    }

    public function edit(Request $request, Industry $industry): Response
    {
        $r = IndustryContent::record($industry);
        $content = IndustryContent::content($industry);
        // A super admin can publish an unsigned page: they sign it as the reviewer.
        $user = $request->user();
        $signer = trim((string) $r?->reviewer) === '' && $user instanceof User && $user->hasRole(\App\Domains\Identity\Authorization\Permissions::SUPER_ADMIN) ? $user->name : $r?->reviewer;

        return Inertia::render('admin/content/IndustryEdit', [
            'industry' => ['slug' => $industry->slug, 'name' => $industry->name, 'tier' => $industry->tier->value, 'licenseRequired' => (bool) $industry->license_required, 'terms' => $industry->terms()],
            'content' => $content, 'reviewer' => $r?->reviewer, 'published' => $r?->status === 'published', 'reviewedOn' => $r?->reviewed_on,
            'problems' => array_values(IndustryContent::problems($industry, $content, $signer)),
            'disclosure' => ($dc = IndustryContent::disclosure($industry)) !== null ? \App\Domains\Content\Tokens::fill($dc) : null, 'tierFact' => IndustryContent::tierFact($industry),
            'samples' => ['state' => IndustryContent::placeSummary($industry, 'Texas'), 'city' => IndustryContent::placeSummary($industry, 'Texas', 'Austin')],
            'industries' => Industry::query()->where('id', '!=', $industry->id)->orderBy('name')->get(['slug', 'name'])->map(fn ($i) => ['slug' => $i->slug, 'name' => $i->name])->values(),
            'parts' => IndustryContent::PARTS, 'status' => session('status'),
            'seo' => SeoData::private('Industry content: '.$industry->name)->toArray(),
        ]);
    }

    public function save(Request $request, Industry $industry): RedirectResponse
    {
        $d = $request->validate(['content' => ['required', 'array'], 'reviewer' => ['nullable', 'string', 'max:120']]);
        IndustryContent::save($industry, (array) $d['content'], $d['reviewer'] ?? null, $this->user($request));

        return back()->with('status', 'industry-content-saved');
    }

    public function act(Request $request, Industry $industry, string $action): RedirectResponse
    {
        $action === 'publish' ? IndustryContent::publish($industry, $this->user($request)) : IndustryContent::unpublish($industry, $this->user($request));

        return back()->with('status', 'industry-content-'.$action);
    }

    /** Fill industries that have no content yet from the drafted pack (never overwrites). */
    public function install(Request $request): RedirectResponse
    {
        IndustryContent::install($this->user($request));

        return back()->with('status', 'industry-content-installed');
    }

    private function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }
}
