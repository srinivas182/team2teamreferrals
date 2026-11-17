<?php

declare(strict_types=1);

namespace App\Domains\Partners\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Partners\Partner;
use App\Domains\Partners\PartnerCategory;
use App\Domains\Partners\PartnerListing;
use App\Domains\Partners\Partners;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Partners: the review queue (approve, reject with a reason, suspend, reinstate) and the categories. */
final class AdminPartnersController
{
    public function index(Request $request): Response
    {
        $status = (string) ($request->validate(['status' => ['nullable', Rule::in(array_keys(Partner::STATUSES))]])['status'] ?? '') ?: 'pending';
        $counts = DB::table('partners')->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $rows = Partner::query()->with(['category', 'listings.city.state'])->where('status', $status)->orderBy($status === 'pending' ? 'id' : 'reviewed_at', $status === 'pending' ? 'asc' : 'desc')->limit(200)->get();
        $endorsements = DB::table('partner_endorsements')->whereIn('partner_id', $rows->pluck('id'))->groupBy('partner_id')->selectRaw('partner_id, count(*) as n')->pluck('n', 'partner_id');
        $enquiries = DB::table('partner_enquiries')->whereIn('partner_id', $rows->pluck('id'))->groupBy('partner_id')->selectRaw('partner_id, count(*) as n')->pluck('n', 'partner_id');

        return Inertia::render('admin/Partners', [
            'status' => $status,
            'counts' => collect(array_keys(Partner::STATUSES))->mapWithKeys(fn ($k) => [$k => $counts[$k] ?? 0]),
            'rows' => $rows->map(fn (Partner $p) => [
                'id' => $p->ulid, 'slug' => $p->slug, 'name' => $p->business_name, 'category' => $p->category->name, 'contact' => $p->contact_name, 'email' => $p->email, 'phone' => $p->phone,
                'website' => $p->website, 'description' => $p->description, 'logo' => $p->logoUrl(),
                'licensed' => $p->category->licensed, 'licenseLabel' => $p->category->license_label, 'licenseNumber' => $p->license_number, 'licenseState' => $p->license_state,
                'licenseLookup' => $p->category->lookupUrl($p->license_number), 'licenseVerified' => $p->license_verified_at?->toDateString(),
                'offer' => $p->offer_title !== null ? trim($p->offer_title.': '.$p->offer_details) : null,
                'cities' => $p->listings->map(fn (PartnerListing $l) => $l->city->name.', '.$l->city->state->code)->sort()->values(),
                'reason' => $p->status_reason, 'applied' => $p->created_at->toDateString(), 'reviewed' => $p->reviewed_at?->toDateString(),
                'endorsements' => (int) ($endorsements[$p->id] ?? 0), 'enquiries' => (int) ($enquiries[$p->id] ?? 0),
            ])->values(),
            'enabled' => Partners::enabled(),
            'flash' => session('status'),
            'seo' => SeoData::private('Partners')->toArray(),
        ]);
    }

    public function decide(Request $request, string $partner): RedirectResponse
    {
        $p = Partner::query()->with('category')->where('ulid', $partner)->firstOrFail();
        $action = (string) $request->route('action');
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        match ($action) {
            'approve' => Partners::approve($p, $user, $request->boolean('license_checked')),
            'reject' => Partners::reject($p, $user, $this->reason($request)),
            'suspend' => Partners::suspend($p, $user, $this->reason($request)),
            'reinstate' => $p->status === 'suspended' ? Partners::reinstate($p, $user) : abort(422),
            default => abort(404),
        };

        return back()->with('status', ['approve' => 'partner-approved', 'reject' => 'partner-rejected', 'suspend' => 'partner-suspended', 'reinstate' => 'partner-reinstated'][$action]);
    }

    public function categories(): Response
    {
        return Inertia::render('admin/PartnerCategories', [
            'categories' => PartnerCategory::query()->orderBy('sort')->orderBy('name')->get()->map(fn (PartnerCategory $c) => [
                'id' => $c->id, 'slug' => $c->slug, 'name' => $c->name, 'singular' => $c->singular, 'licensed' => $c->licensed, 'licenseLabel' => $c->license_label,
                'licenseLookupUrl' => $c->license_lookup_url, 'schemaType' => $c->schema_type, 'faq' => $c->faq ?? [], 'sort' => $c->sort, 'active' => $c->is_active,
                'partners' => Partner::query()->where('category_id', $c->id)->count(),
            ])->values(),
            'schemaTypes' => PartnerCategory::SCHEMA_TYPES,
            'flash' => session('status'),
            'seo' => SeoData::private('Partner categories')->toArray(),
        ]);
    }

    public function saveCategory(Request $request, ?int $category = null): RedirectResponse
    {
        $id = $request->route('category') !== null ? (int) $request->route('category') : null;
        $c = $id !== null ? PartnerCategory::query()->findOrFail($id) : new PartnerCategory;
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'singular' => ['required', 'string', 'max:80'], 'licensed' => ['boolean'], 'license_label' => ['nullable', 'string', 'max:60', 'required_if:licensed,true'],
            'license_lookup_url' => ['nullable', 'string', 'max:300', 'regex:#^https://[^\s]+\{number\}#'], 'schema_type' => ['required', Rule::in(PartnerCategory::SCHEMA_TYPES)],
            'faq' => ['nullable', 'array', 'max:8'], 'faq.*.q' => ['required', 'string', 'max:200'], 'faq.*.a' => ['required', 'string', 'max:1200'], 'sort' => ['nullable', 'integer', 'between:0,999'], 'active' => ['boolean'],
        ], ['license_lookup_url.regex' => 'Use an https:// address with {number} where the license number goes.']);
        if (! $c->exists) {
            $base = Str::slug($d['name']);
            $slug = $base;
            for ($i = 2; PartnerCategory::query()->where('slug', $slug)->exists(); $i++) {
                $slug = $base.'-'.$i;
            }
            $c->slug = $slug;
        }
        $c->fill(['name' => $d['name'], 'singular' => $d['singular'], 'licensed' => (bool) ($d['licensed'] ?? false), 'license_label' => $d['license_label'] ?? null,
            'license_lookup_url' => $d['license_lookup_url'] ?? null, 'schema_type' => $d['schema_type'], 'faq' => array_values((array) ($d['faq'] ?? [])),
            'sort' => (int) ($d['sort'] ?? 0), 'is_active' => (bool) ($d['active'] ?? true)])->save();
        activity('partners')->causedBy($request->user())->performedOn($c)->event('partner-category-saved')->log("Saved partner category {$c->name}");

        return back()->with('status', 'partner-category-saved');
    }

    private function reason(Request $request): string
    {
        return trim((string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.required' => 'Give a reason: the business sees it.'])['reason']);
    }
}
