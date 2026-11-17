<?php

declare(strict_types=1);

namespace App\Domains\Partners\Http;

use App\Domains\Billing\BillingAccounts;
use App\Domains\Billing\Invoicing;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Partners\Partner;
use App\Domains\Partners\PartnerBilling;
use App\Domains\Partners\PartnerListing;
use App\Domains\Partners\PartnerMail;
use App\Domains\Partners\Partners;
use App\Domains\Partners\PartnerStats;
use App\Domains\Platform\Payments\Stripe;
use App\Domains\Seo\SeoData;
use App\Support\ImageMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The partner account (Sprint 47b). Partners sign in with a link emailed to the address on their listing (no
 * password to forget), then manage their listing (changes are reviewed before they show), cities, card, plan,
 * enquiries, invoices and stats.
 */
final class PartnerPortalController
{
    private const SESSION = 'partner_portal';

    public function home(Request $request): Response|RedirectResponse
    {
        $email = $this->email($request);
        $partners = $email !== null ? Partner::query()->with('category')->where('email', $email)->orderBy('business_name')->get() : collect();
        if ($partners->count() === 1) {
            return redirect('/partner-portal/'.$partners->first()?->ulid);
        }

        return Inertia::render('partners/portal/Home', [
            'signedIn' => $email !== null,
            'partners' => $partners->map(fn (Partner $p) => ['id' => $p->ulid, 'name' => $p->business_name, 'category' => $p->category->name, 'status' => Partner::STATUSES[$p->status]])->values(),
            'status' => session('status'),
            'seo' => SeoData::private('Partner account')->toArray(),
        ]);
    }

    /** Email a sign-in link. The answer is the same whether or not we know the address, so it can't be probed. */
    public function link(Request $request): RedirectResponse
    {
        $email = strtolower(trim((string) $request->validate(['email' => ['required', 'email:rfc', 'max:190']])['email']));
        if (Partner::query()->where('email', $email)->exists()) {
            $url = URL::temporarySignedRoute('partners.portal.enter', now()->addMinutes(30), ['t' => encrypt($email)]);
            Notification::route('mail', $email)->notify(new PartnerMail('Your sign-in link', ['Use this link to open your partner account. It works for 30 minutes.', 'If you didn’t ask for it, you can ignore this email.'], 'Open my partner account', $url));
        }

        return back()->with('status', 'partner-link-sent');
    }

    public function enter(Request $request): RedirectResponse
    {
        $email = rescue(fn () => decrypt((string) $request->query('t')), null, false);
        abort_unless(is_string($email) && Partner::query()->where('email', $email)->exists(), 403);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION, $email);

        return redirect('/partner-portal');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION);

        return redirect('/partner-portal')->with('status', 'partner-signed-out');
    }

    public function show(Request $request, string $partner): Response
    {
        $p = $this->find($request, $partner);
        $s = PartnerBilling::settings();
        $from = now()->subDays(29)->toDateString();
        $account = BillingAccounts::account('partner', $p->id);
        $stripe = app(Stripe::class);

        return Inertia::render('partners/portal/Show', [
            'partner' => [
                'id' => $p->ulid, 'slug' => $p->slug, 'name' => $p->business_name, 'category' => $p->category->name, 'licensed' => $p->category->licensed, 'licenseLabel' => $p->category->license_label,
                'status' => $p->status, 'statusLabel' => Partner::STATUSES[$p->status], 'reason' => $p->status_reason, 'live' => $p->isLive(),
                'details' => ['business_name' => $p->business_name, 'description' => $p->description, 'phone' => $p->phone, 'website' => $p->website, 'license_number' => $p->license_number,
                    'license_state' => $p->license_state, 'offer_title' => $p->offer_title, 'offer_details' => $p->offer_details, 'offer_expires_on' => $p->offer_expires_on?->toDateString()],
                'logo' => $p->logoUrl(), 'pending' => $p->pending_changes !== null ? array_diff_key($p->pending_changes, ['logo_path' => 1]) + ['logo' => isset($p->pending_changes['logo_path'])] : null,
            ],
            'billing' => [
                'status' => $p->billing_status, 'statusLabel' => Partner::BILLING[$p->billing_status] ?? $p->billing_status, 'cycle' => $p->billing_cycle,
                'trialEnds' => $p->trial_ends_at?->toDateString(), 'paidUntil' => $p->paid_until?->toDateString(), 'cancelAt' => $p->cancel_at?->toDateString(),
                'monthly' => PartnerBilling::monthlyCents($p) / 100, 'annual' => PartnerBilling::annualCents($p) / 100, 'price' => $s['price'], 'discount' => $s['annualDiscount'],
                'card' => $account->payment_method_id !== null ? ['brand' => (string) $account->card_brand, 'last4' => (string) $account->card_last4, 'exp' => sprintf('%02d/%d', $account->card_exp_month, $account->card_exp_year)] : null,
                'invoices' => DB::table('invoices')->where(['owner_type' => 'partner', 'owner_id' => $p->id])->orderByDesc('id')->limit(24)->get()
                    ->map(fn ($i) => ['id' => (int) $i->id, 'number' => (string) $i->number, 'date' => substr((string) $i->created_at, 0, 10), 'total' => (int) $i->total_cents / 100, 'status' => (string) $i->status,
                        'failure' => $i->failure, 'canPay' => in_array($i->status, ['failed', 'action_required', 'open'], true)])->values(),
            ],
            'stripeKey' => $stripe->connected() ? $stripe->publishableKey() : null,
            'listings' => PartnerListing::query()->with('city.state')->where('partner_id', $p->id)->orderBy('id')->get()->map(fn (PartnerListing $l) => [
                'id' => $l->id, 'city' => $l->city->name.', '.$l->city->state->code, 'status' => $l->status, 'position' => Partners::waitlistPosition($l),
                'href' => '/partners/'.$p->category->slug.'/'.strtolower((string) $l->city->state->code).'/'.$l->city->slug,
            ])->values(),
            'cap' => $s['cap'],
            'stats' => PartnerStats::report($p, $from, now()->toDateString()),
            'enquiries' => DB::table('partner_enquiries as e')->leftJoin('cities as c', 'c.id', '=', 'e.city_id')->where('e.partner_id', $p->id)->orderByDesc('e.id')->limit(50)
                ->get(['e.name', 'e.email', 'e.phone', 'e.message', 'e.created_at', 'c.name as city'])->map(fn ($e) => [...(array) $e, 'created_at' => substr((string) $e->created_at, 0, 16)])->values(),
            'status' => session('status'),
            'seo' => SeoData::private('Partner account')->toArray(),
        ]);
    }

    /** Listing changes wait for review; the current listing stays up meanwhile. Before approval they apply at once. */
    public function details(Request $request, string $partner): RedirectResponse
    {
        $p = $this->find($request, $partner);
        $d = $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:120'], 'description' => ['required', 'string', 'min:40', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+().\-\s]{7,32}$/'], 'website' => ['nullable', 'url:https', 'max:300'],
            'license_number' => [$p->category->licensed ? 'required' : 'nullable', 'string', 'max:60'], 'license_state' => ['nullable', 'string', 'size:2'],
            'offer_title' => ['nullable', 'string', 'max:120'], 'offer_details' => ['nullable', 'string', 'max:500', 'required_with:offer_title'], 'offer_expires_on' => ['nullable', 'date', 'after:today'],
            'logo' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
        ], ['website.url' => 'Use your full https:// address.']);
        $changes = array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_diff_key($d, ['logo' => 1]));
        $changes['license_state'] = isset($changes['license_state']) && $changes['license_state'] !== '' ? strtoupper((string) $changes['license_state']) : null;
        if ($request->hasFile('logo')) {
            $bytes = (string) file_get_contents((string) $request->file('logo')?->getRealPath());
            $type = ImageMetadata::type($bytes) ?? throw ValidationException::withMessages(['logo' => 'Use a JPEG, PNG or WebP image.']);
            $path = 'partners/'.$p->ulid.'-'.Str::lower(Str::random(6)).'.'.['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$type];
            Storage::disk('public')->put($path, ImageMetadata::strip($bytes));
            $changes['logo_path'] = $path;
        }
        if ($p->status === 'pending') {
            Partners::applyChanges($p, $changes);

            return back()->with('status', 'partner-details-saved');
        }
        $old = $p->pending_changes['logo_path'] ?? null;
        if ($old !== null && $old !== ($changes['logo_path'] ?? null)) {
            Storage::disk('public')->delete((string) $old);
        }
        $p->forceFill(['pending_changes' => $changes, 'changes_submitted_at' => now()])->save();
        foreach (Partners::staff() as $u) {
            app(\App\Domains\Messaging\Notifier::class)->send($u, 'admin.partners', ['headline' => 'Listing changes to review: '.$p->business_name, 'body' => 'A partner updated their listing.'], url('/admin/partners?status=changes'));
        }

        return back()->with('status', 'partner-changes-submitted');
    }

    /** Add cities (listed where there's room, otherwise waitlisted) or remove one. The price follows from the next bill. */
    public function addCities(Request $request, string $partner): RedirectResponse
    {
        $p = $this->find($request, $partner);
        $ids = array_map('intval', (array) $request->validate(['cities' => ['required', 'array', 'min:1', 'max:25'], 'cities.*' => ['integer']])['cities']);
        $have = PartnerListing::query()->where('partner_id', $p->id)->count();
        abort_if($have + count($ids) > 25, 422, 'Up to 25 cities per listing.');
        foreach (City::query()->whereIn('id', $ids)->where('is_enabled', true)->pluck('id') as $cityId) {
            PartnerListing::query()->firstOrCreate(['partner_id' => $p->id, 'city_id' => $cityId], ['category_id' => $p->category_id, 'status' => 'waitlist', 'waitlisted_at' => now()]);
        }
        if ($p->isLive()) {
            Partners::allocate($p);
            Partners::changed($p);
        }

        return back()->with('status', 'partner-cities-saved');
    }

    public function removeCity(Request $request, string $partner, int $listing): RedirectResponse
    {
        $p = $this->find($request, $partner);
        $l = PartnerListing::query()->where(['id' => (int) $request->route('listing'), 'partner_id' => $p->id])->firstOrFail();
        abort_if(PartnerListing::query()->where('partner_id', $p->id)->count() === 1, 422, 'Keep at least one city, or cancel your listing instead.');
        Partners::changed($p);
        $l->delete();
        Partners::fillOpenings($l->city_id, $l->category_id);

        return back()->with('status', 'partner-cities-saved');
    }

    public function setupIntent(Request $request, string $partner): JsonResponse
    {
        $p = $this->find($request, $partner);

        return response()->json(['clientSecret' => app(BillingAccounts::class)->setupIntent('partner', $p->id)]);
    }

    public function saveCard(Request $request, string $partner): RedirectResponse
    {
        $p = $this->find($request, $partner);
        app(BillingAccounts::class)->saveCard('partner', $p->id, (string) $request->validate(['payment_method' => ['required', 'string', 'starts_with:pm_', 'max:64']])['payment_method']);
        PartnerBilling::cardSaved($p);

        return back()->with('status', 'card-saved');
    }

    public function payNow(Request $request, string $partner, int $invoice): RedirectResponse|JsonResponse
    {
        $p = $this->find($request, $partner);
        $id = (int) $request->route('invoice');
        abort_unless(DB::table('invoices')->where(['id' => $id, 'owner_type' => 'partner', 'owner_id' => $p->id])->exists(), 404);
        $result = app(Invoicing::class)->charge($id);
        if ($result === 'action_required') {
            $pi = app(Stripe::class)->api('GET', 'payment_intents/'.DB::table('invoices')->where('id', $id)->value('stripe_payment_intent'));

            return response()->json(['clientSecret' => $pi['client_secret'] ?? null]);
        }

        return back()->with('status', $result === 'paid' ? 'invoice-paid' : 'invoice-failed');
    }

    public function invoicePdf(Request $request, string $partner, int $invoice): StreamedResponse
    {
        $p = $this->find($request, $partner);
        $path = DB::table('invoices')->where(['id' => (int) $request->route('invoice'), 'owner_type' => 'partner', 'owner_id' => $p->id])->value('pdf_path');
        abort_if($path === null || ! Storage::disk('private')->exists((string) $path), 404);

        return Storage::disk('private')->download((string) $path);
    }

    /** Monthly or annual, from the next renewal. */
    public function cycle(Request $request, string $partner): RedirectResponse
    {
        $p = $this->find($request, $partner);
        $p->forceFill(['billing_cycle' => (string) $request->validate(['cycle' => ['required', 'in:monthly,annual']])['cycle']])->save();

        return back()->with('status', 'partner-plan-saved');
    }

    public function plan(Request $request, string $partner): RedirectResponse
    {
        $p = $this->find($request, $partner);
        $action = (string) $request->route('action');
        abort_unless($p->status === 'approved', 422);
        match ($action) {
            'cancel' => PartnerBilling::cancel($p),
            'resume' => PartnerBilling::resume($p),
            'restart' => PartnerBilling::restart($p),
            default => abort(404),
        };

        return back()->with('status', ['cancel' => 'partner-cancelled', 'resume' => 'partner-resumed', 'restart' => 'partner-restarted'][$action]);
    }

    /** Daily views, clicks, enquiries and shares by city, for a spreadsheet. */
    public function statsCsv(Request $request, string $partner): StreamedResponse
    {
        $p = $this->find($request, $partner);
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = (string) ($d['from'] ?? now()->subDays(89)->toDateString());
        $to = (string) ($d['to'] ?? now()->toDateString());
        $rows = DB::table('partner_stats as s')->leftJoin('cities as c', 'c.id', '=', 's.city_id')->leftJoin('states as st', 'st.id', '=', 'c.state_id')
            ->where('s.partner_id', $p->id)->whereBetween('s.day', [$from, $to])->orderBy('s.day')->orderBy('c.name')
            ->get(['s.day', 'c.name as city', 'st.code as state', 's.views', 's.clicks', 's.enquiries', 's.shares']);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Date', 'City', 'Views', 'Clicks', 'Enquiries', 'Shares by members'], escape: '');
            foreach ($rows as $r) {
                // Leading = + - @ would run as a formula in a spreadsheet: keep text as text.
                $city = $r->city !== null ? $r->city.', '.$r->state : 'Your page';
                fputcsv($out, [substr((string) $r->day, 0, 10), preg_match('/^[=+\-@]/', $city) ? "'".$city : $city, $r->views, $r->clicks, $r->enquiries, $r->shares], escape: '');
            }
            fclose($out);
        }, 'partner-stats-'.$p->slug.'-'.$from.'-to-'.$to.'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    private function email(Request $request): ?string
    {
        $e = $request->session()->get(self::SESSION);

        return is_string($e) ? $e : null;
    }

    private function find(Request $request, string $ulid): Partner
    {
        $email = $this->email($request);
        abort_if($email === null, 403, 'Please sign in to your partner account.');

        return Partner::query()->with('category')->where(['ulid' => (string) ($request->route('partner') ?? $ulid), 'email' => $email])->firstOrFail();
    }
}
