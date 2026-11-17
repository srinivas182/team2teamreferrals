<?php

declare(strict_types=1);

namespace App\Domains\Partners;

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Directory\Directory;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Platform\Modules\Modules;
use App\Domains\Seo\Events\PublicUrlsChanged;
use App\Support\ImageMetadata;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The partner directory (Sprint 47a): applications, review, fair placement on city pages, exclusive enquiries and
 * member endorsements.
 *
 * Built to stay on the right side of RESPA Section 8: partners pay a flat fee to advertise (never per lead or per
 * closing), placement rotates evenly among everyone listed in a city, every block is labelled "Advertisement", and
 * the page explains how the list works. Members get nothing for sending clients to a partner.
 */
final class Partners
{
    /** How many partners per category show in the "Local partners" block on a city page. */
    public const CITY_BLOCK = 3;

    public static function enabled(): bool
    {
        return app(Modules::class)->enabled('partner_directory');
    }

    /**
     * A business applies. Listings are created for its chosen cities but nothing is public until staff approve.
     *
     * @param  array{category: string, business_name: string, contact_name: string, email: string, phone?: ?string, website?: ?string, description: string, license_number?: ?string, license_state?: ?string, offer_title?: ?string, offer_details?: ?string, offer_expires_on?: ?string, cities: list<int>}  $d
     */
    public static function apply(array $d, ?UploadedFile $logo, ?string $ipHash): Partner
    {
        $category = PartnerCategory::query()->where(['slug' => $d['category'], 'is_active' => true])->firstOrFail();
        if ($category->licensed && blank($d['license_number'] ?? null)) {
            throw ValidationException::withMessages(['license_number' => "Add your {$category->license_label}: we check it before your listing goes live."]);
        }
        $cities = City::query()->whereIn('id', $d['cities'])->where('is_enabled', true)->pluck('id');
        if ($cities->isEmpty()) {
            throw ValidationException::withMessages(['cities' => 'Choose at least one city you serve.']);
        }
        // Check the logo before anything is saved, so a bad file never leaves a half-made application.
        if ($logo !== null && ImageMetadata::type((string) file_get_contents((string) $logo->getRealPath())) === null) {
            throw ValidationException::withMessages(['logo' => 'Use a JPEG, PNG or WebP image.']);
        }
        $partner = DB::transaction(function () use ($d, $category, $cities, $ipHash): Partner {
            $p = Partner::create([
                'slug' => self::slug($d['business_name']), 'category_id' => $category->id, 'business_name' => trim($d['business_name']), 'contact_name' => trim($d['contact_name']),
                'email' => strtolower(trim($d['email'])), 'phone' => $d['phone'] ?? null, 'website' => $d['website'] ?? null, 'description' => trim($d['description']),
                'license_number' => filled($d['license_number'] ?? null) ? trim((string) $d['license_number']) : null, 'license_state' => isset($d['license_state']) && $d['license_state'] !== '' ? strtoupper((string) $d['license_state']) : null,
                'offer_title' => $d['offer_title'] ?? null, 'offer_details' => $d['offer_details'] ?? null, 'offer_expires_on' => $d['offer_expires_on'] ?? null, 'ip_hash' => $ipHash,
            ]);
            foreach ($cities as $cityId) {
                PartnerListing::create(['partner_id' => $p->id, 'category_id' => $category->id, 'city_id' => $cityId]);
            }

            return $p;
        });
        if ($logo !== null) {
            self::storeLogo($partner, $logo);
        }
        activity('partners')->performedOn($partner)->event('partner-applied')->log("{$partner->business_name} applied to advertise ({$category->name})");
        self::mail($partner, 'We got your application', [
            "Thanks for applying to advertise {$partner->business_name} in the partner directory.",
            $category->licensed ? "We check your {$category->license_label} with the regulator, then review your listing. Most reviews take one or two business days." : 'We review every listing by hand. Most reviews take one or two business days.',
            'You don’t pay anything until your listing is approved.',
        ]);
        foreach (self::staff() as $u) {
            app(Notifier::class)->send($u, 'admin.partners', ['headline' => 'New partner application: '.$partner->business_name, 'body' => $category->name.', '.$cities->count().' '.Str::plural('city', $cities->count()).'.'], url('/admin/partners'));
        }

        return $partner;
    }

    /** Logos are checked by their bytes (JPEG, PNG or WebP only) and stripped of metadata such as GPS before saving. */
    public static function storeLogo(Partner $partner, UploadedFile $file): void
    {
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        $type = ImageMetadata::type($bytes);
        if ($type === null) {
            throw ValidationException::withMessages(['logo' => 'Use a JPEG, PNG or WebP image.']);
        }
        $path = 'partners/'.$partner->ulid.'-'.Str::lower(Str::random(6)).'.'.['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$type];
        Storage::disk('public')->put($path, ImageMetadata::strip($bytes));
        if ($partner->logo_path !== null) {
            Storage::disk('public')->delete($partner->logo_path);
        }
        $partner->forceFill(['logo_path' => $path])->save();
    }

    /** Approve. Licensed categories need the license number, and the reviewer must confirm they checked it. */
    public static function approve(Partner $partner, User $by, bool $licenseChecked): void
    {
        $category = $partner->category;
        if ($category->licensed && $partner->license_number === null) {
            throw ValidationException::withMessages(['license' => "This business hasn’t given its {$category->license_label}. Reject it with a reason, or ask them to apply again."]);
        }
        if ($category->licensed && ! $licenseChecked && $partner->license_verified_at === null) {
            throw ValidationException::withMessages(['license' => "Check the {$category->license_label} with the regulator first, then tick the box."]);
        }
        $partner->forceFill(['status' => 'approved', 'status_reason' => null, 'reviewed_by' => $by->id, 'reviewed_at' => now(),
            'license_verified_at' => $licenseChecked && $partner->license_number !== null ? now() : $partner->license_verified_at])->save();
        activity('partners')->causedBy($by)->performedOn($partner)->event('partner-approved')->log("Approved partner {$partner->business_name}");
        PartnerBilling::start($partner);
        $waiting = self::allocate($partner);
        $lines = ["{$partner->business_name} is now listed in the partner directory.", 'Enquiries from your listing come straight to this email address. They are yours alone: we never sell or share them.'];
        if ($partner->billing_status === 'trial' && $partner->trial_ends_at !== null) {
            $lines[] = 'Your free trial runs until '.$partner->trial_ends_at->toFormattedDateString().'. Add a card in your partner account before then to stay listed.';
        }
        if ($waiting !== []) {
            $lines[] = 'These cities already have the most partners we show for your category, so you are on the waitlist there (and not charged for them): '.implode('; ', $waiting).'. We’ll email you when a spot opens.';
        }
        $lines[] = 'Manage your listing, card, cities and enquiries at '.url('/partner-portal').' (we email you a sign-in link).';
        self::mail($partner, 'Your listing is live', $lines, 'See your listing', url($partner->path()));
        self::changed($partner);
    }

    public static function reject(Partner $partner, User $by, string $reason): void
    {
        $partner->forceFill(['status' => 'rejected', 'status_reason' => $reason, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
        activity('partners')->causedBy($by)->performedOn($partner)->event('partner-rejected')->log("Did not approve partner {$partner->business_name}: {$reason}");
        self::mail($partner, 'About your listing application', ["We couldn’t approve {$partner->business_name} for the partner directory this time.", 'Reason: '.$reason, 'You are welcome to apply again once this is sorted. Nothing was charged.']);
        self::changed($partner);
    }

    public static function suspend(Partner $partner, User $by, string $reason): void
    {
        $partner->forceFill(['status' => 'suspended', 'status_reason' => $reason, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
        activity('partners')->causedBy($by)->performedOn($partner)->event('partner-suspended')->log("Suspended partner {$partner->business_name}: {$reason}");
        self::mail($partner, 'Your listing is paused', ["We have paused the listing for {$partner->business_name}.", 'Reason: '.$reason, 'Reply to this email if you have questions.']);
        self::release($partner);
        self::changed($partner);
    }

    public static function reinstate(Partner $partner, User $by): void
    {
        $partner->forceFill(['status' => 'approved', 'status_reason' => null, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
        activity('partners')->causedBy($by)->performedOn($partner)->event('partner-reinstated')->log("Reinstated partner {$partner->business_name}");
        self::allocate($partner);
        self::mail($partner, 'Your listing is live again', ["The listing for {$partner->business_name} is back in the partner directory."], 'See your listing', url($partner->path()));
        self::changed($partner);
    }

    /**
     * Fair rotation: everyone listed takes the top spot in turn, one day each, starting from a different place in
     * each city and category. Same order all day (pages are cached), and equal exposure over time.
     *
     * @template T
     *
     * @param  list<T>  $items  in a stable order (by listing id)
     * @return list<T>
     */
    public static function rotate(array $items, string $key, ?int $day = null): array
    {
        $n = count($items);
        if ($n < 2) {
            return $items;
        }
        $day ??= intdiv(now()->getTimestamp(), 86400);
        $shift = (crc32($key) + $day) % $n;

        return [...array_slice($items, $shift), ...array_slice($items, 0, $shift)];
    }

    /**
     * Approved partners listed in a city, by category, in today's rotation.
     *
     * @return Collection<int, array{category: PartnerCategory, partners: list<Partner>}>
     */
    public static function inCity(City $city, ?PartnerCategory $only = null): Collection
    {
        $listings = PartnerListing::query()->with(['partner.category'])
            ->where(['city_id' => $city->id, 'status' => 'active'])
            ->when($only !== null, fn ($q) => $q->where('category_id', $only?->id))
            ->whereHas('partner', fn ($q) => $q->live())
            ->orderBy('id')->get();

        return $listings->groupBy('category_id')->map(function (Collection $group, int|string $categoryId) use ($city): array {
            $partners = array_values($group->map(fn (PartnerListing $l) => $l->partner)->all());

            return ['category' => $partners[0]->category, 'partners' => self::rotate($partners, $city->id.':'.$categoryId)];
        })->sortBy(fn ($g) => $g['category']->sort)->values();
    }

    /**
     * The "Local partners" block on a city page: a few per category, labelled as advertising, with a link to the rest.
     *
     * @return list<array{category: array{slug: string, name: string}, partners: list<array<string, mixed>>, total: int, href: string}>
     */
    public static function cityBlock(City $city): array
    {
        if (! self::enabled()) {
            return [];
        }
        $state = strtolower((string) $city->state->code);

        return array_values(self::inCity($city)->map(fn ($g) => [
            'category' => ['slug' => $g['category']->slug, 'name' => $g['category']->name],
            'partners' => array_map(fn (Partner $p) => self::card($p), array_slice($g['partners'], 0, self::CITY_BLOCK)),
            'total' => count($g['partners']),
            'href' => '/partners/'.$g['category']->slug.'/'.$state.'/'.$city->slug,
        ])->all());
    }

    /**
     * What visitors see about a partner (public facts only).
     *
     * @return array<string, mixed>
     */
    public static function card(Partner $p): array
    {
        $c = $p->category;

        return [
            'slug' => $p->slug, 'name' => $p->business_name, 'category' => $c->singular, 'logo' => $p->logoUrl(), 'summary' => Str::limit($p->description, 180),
            'phone' => $p->phone, 'website' => $p->website, 'offer' => $p->offer(),
            'license' => $p->license_number !== null && $p->license_verified_at !== null
                ? ['label' => (string) ($c->license_label ?? 'License'), 'number' => $p->license_number, 'state' => $p->license_state, 'verified' => $p->license_verified_at->toDateString(), 'lookup' => $c->lookupUrl($p->license_number)]
                : null,
            'endorsements' => DB::table('partner_endorsements')->where('partner_id', $p->id)->count(),
        ];
    }

    /**
     * An enquiry goes to this partner only, never to anyone else, and is never resold.
     *
     * @param  array{name: string, email: string, phone?: ?string, message: string}  $d
     */
    public static function enquire(Partner $partner, array $d, ?City $city, string $ipHash): void
    {
        DB::table('partner_enquiries')->insert(['ulid' => strtolower((string) Str::ulid()), 'partner_id' => $partner->id, 'city_id' => $city?->id, 'name' => $d['name'], 'email' => strtolower((string) $d['email']),
            'phone' => $d['phone'] ?? null, 'message' => $d['message'], 'ip_hash' => $ipHash, 'created_at' => now(), 'updated_at' => now()]);
        PartnerStats::bump($partner->id, $city?->id, 'enquiries');
        $where = $city !== null ? ' in '.$city->name : '';
        self::mail($partner, 'New enquiry from '.$d['name'], [
            "{$d['name']} wrote to {$partner->business_name} through your listing{$where}:",
            $d['message'],
            'Email: '.$d['email'].(filled($d['phone'] ?? null) ? ' · Phone: '.$d['phone'] : ''),
            'Reply to this email to answer them directly. This enquiry was sent to you alone.',
        ], null, null, (string) $d['email']);
        Notification::route('mail', (string) $d['email'])->notify(new PartnerMail('Your message to '.$partner->business_name, [
            "Thanks, {$d['name']}. Your message went to {$partner->business_name} only. They will reply to you directly.",
            'We don’t sell or share your details with anyone else.',
        ]));
    }

    /**
     * Apply listing changes (straight away before approval, or once staff approve them). A new license number
     * needs checking again before the badge returns.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function applyChanges(Partner $partner, array $changes, bool $licenseChecked = false): void
    {
        $fields = array_intersect_key($changes, array_flip(['business_name', 'description', 'phone', 'website', 'license_number', 'license_state', 'offer_title', 'offer_details', 'offer_expires_on']));
        if (array_key_exists('license_number', $fields) && $fields['license_number'] !== $partner->license_number) {
            $partner->license_verified_at = $licenseChecked && $fields['license_number'] !== null ? now() : null;
        }
        if (isset($changes['logo_path'])) {
            if ($partner->logo_path !== null) {
                Storage::disk('public')->delete($partner->logo_path);
            }
            $partner->logo_path = (string) $changes['logo_path'];
        }
        $partner->fill($fields)->forceFill(['pending_changes' => null, 'changes_submitted_at' => null])->save();
        self::changed($partner);
    }

    /** Staff approve waiting listing changes. Licensed fields need the new number checked first. */
    public static function approveChanges(Partner $partner, User $by, bool $licenseChecked): void
    {
        $changes = $partner->pending_changes ?? [];
        $newLicense = array_key_exists('license_number', $changes) && $changes['license_number'] !== $partner->license_number;
        if ($newLicense && $partner->category->licensed && ! $licenseChecked) {
            throw ValidationException::withMessages(['license' => "The {$partner->category->license_label} changed: check it with the regulator first, then tick the box."]);
        }
        self::applyChanges($partner, $changes, $licenseChecked);
        activity('partners')->causedBy($by)->performedOn($partner)->event('partner-changes-approved')->log("Approved listing changes for {$partner->business_name}");
        self::mail($partner, 'Your listing changes are live', ["The changes to {$partner->business_name}'s listing are now showing."], 'See your listing', url($partner->path()));
    }

    public static function rejectChanges(Partner $partner, User $by, string $reason): void
    {
        if (isset($partner->pending_changes['logo_path'])) {
            Storage::disk('public')->delete((string) $partner->pending_changes['logo_path']);
        }
        $partner->forceFill(['pending_changes' => null, 'changes_submitted_at' => null])->save();
        activity('partners')->causedBy($by)->performedOn($partner)->event('partner-changes-rejected')->log("Did not approve listing changes for {$partner->business_name}: {$reason}");
        self::mail($partner, 'About your listing changes', ["We couldn’t approve the changes to {$partner->business_name}'s listing. Your listing is unchanged.", 'Reason: '.$reason]);
    }

    /**
     * A member sends a partner's page to their client, from the member's own name. The client's address is used to
     * send this one email and is not kept.
     *
     * @param  array{name: string, email: string, note?: ?string}  $d
     */
    public static function share(Partner $partner, User $member, array $d): void
    {
        abort_unless($partner->isLive(), 404);
        $lines = ["Hi {$d['name']},", "{$member->name} thought {$partner->business_name} ({$partner->category->singular}) could help you."];
        if (filled($d['note'] ?? null)) {
            $lines[] = '“'.trim((string) $d['note']).'”';
        }
        $lines[] = 'This is an advertiser in our partner directory. You are free to choose any provider, and nobody is paid for this introduction.';
        Notification::route('mail', (string) $d['email'])->notify(new PartnerMail("{$member->name} recommends {$partner->business_name}", $lines, 'See '.$partner->business_name, url($partner->path()), $member->email));
        DB::table('partner_shares')->insert(['partner_id' => $partner->id, 'user_id' => $member->id, 'created_at' => now()]);
        PartnerStats::bump($partner->id, null, 'shares');
        activity('partners')->causedBy($member)->performedOn($partner)->event('partner-shared')->log("Shared partner {$partner->business_name} with a client");
    }

    /** Most partners shown per city and category (Admin → Partners → Settings). */
    public static function cap(): int
    {
        return max(1, (int) app(\App\Domains\Platform\Settings\Settings::class)->get('partners.cap', '5'));
    }

    /**
     * Place a partner that has just gone live in each of its cities: listed where there's room, otherwise on that
     * city's waitlist (not charged). Returns the cities it is waiting for.
     *
     * @return list<string>
     */
    public static function allocate(Partner $partner): array
    {
        $waiting = [];
        foreach (PartnerListing::query()->with('city.state')->where('partner_id', $partner->id)->orderBy('id')->get() as $l) {
            if ($l->status === 'active' && self::taken($l, true) < self::cap()) {
                continue;
            }
            if (self::taken($l, true) < self::cap()) {
                $l->forceFill(['status' => 'active', 'waitlisted_at' => null])->save();
            } else {
                $l->forceFill(['status' => 'waitlist', 'waitlisted_at' => $l->waitlisted_at ?? now()])->save();
                $waiting[] = $l->city->name.', '.$l->city->state->code;
            }
        }

        return $waiting;
    }

    /** A partner stopped being shown (suspended, payment failed, cancelled, a city removed): offer its places to the waitlist. */
    public static function release(Partner $partner): void
    {
        foreach (PartnerListing::query()->where('partner_id', $partner->id)->get(['city_id', 'category_id'])->unique(fn ($l) => $l->city_id.':'.$l->category_id) as $l) {
            self::fillOpenings((int) $l->city_id, (int) $l->category_id);
        }
    }

    /** Promote waitlisted partners, first come first served, while the city has room for the category. */
    public static function fillOpenings(int $cityId, int $categoryId): void
    {
        $waiting = PartnerListing::query()->with(['partner.category', 'city'])->where(['city_id' => $cityId, 'category_id' => $categoryId, 'status' => 'waitlist'])
            ->whereHas('partner', fn ($q) => $q->live())->orderBy('waitlisted_at')->orderBy('id')->get();
        foreach ($waiting as $l) {
            if (self::taken($l, false) >= self::cap()) {
                break;
            }
            $l->forceFill(['status' => 'active', 'waitlisted_at' => null])->save();
            self::mail($l->partner, 'A spot opened in '.$l->city->name, ["{$l->partner->business_name} is now listed in {$l->city->name}.", 'It is added to your plan from your next bill.'], 'See your listing', url($l->partner->path()));
            self::changed($l->partner);
        }
    }

    /** Listed partners in this listing's city and category (other than this listing's own partner when asked). */
    private static function taken(PartnerListing $l, bool $excludeOwn): int
    {
        return PartnerListing::query()->where(['city_id' => $l->city_id, 'category_id' => $l->category_id, 'status' => 'active'])
            ->when($excludeOwn, fn ($q) => $q->where('partner_id', '!=', $l->partner_id))
            ->whereHas('partner', fn ($q) => $q->live())->count();
    }

    /** Waitlist place for a listing (1 = next). */
    public static function waitlistPosition(PartnerListing $l): ?int
    {
        if ($l->status !== 'waitlist') {
            return null;
        }

        return PartnerListing::query()->where(['city_id' => $l->city_id, 'category_id' => $l->category_id, 'status' => 'waitlist'])
            ->whereHas('partner', fn ($q) => $q->live())
            ->where(fn ($q) => $q->where('waitlisted_at', '<', $l->waitlisted_at)->orWhere(fn ($w) => $w->where('waitlisted_at', $l->waitlisted_at)->where('id', '<', $l->id)))->count() + 1;
    }

    /** The team a member endorses as, when they hold a seat in a network. */
    public static function memberTeam(User $user): ?int
    {
        $id = DB::table('team_members as m')->join('seats as s', 's.primary_team_id', '=', 'm.team_id')->where('m.user_id', $user->id)->whereIn('s.status', Directory::FILLED)->value('m.team_id');

        return $id !== null ? (int) $id : null;
    }

    /** A member vouches for a partner they work with. One per team; it never changes the order. */
    public static function endorse(Partner $partner, User $user, ?string $note): void
    {
        $team = self::memberTeam($user);
        abort_if($team === null || ! $partner->isLive(), 403, 'Only network members can endorse partners.');
        DB::table('partner_endorsements')->updateOrInsert(['partner_id' => $partner->id, 'team_id' => $team], ['user_id' => $user->id, 'note' => $note, 'updated_at' => now(), 'created_at' => now()]);
        activity('partners')->causedBy($user)->performedOn($partner)->event('partner-endorsed')->log("Endorsed partner {$partner->business_name}");
        self::changed($partner);
    }

    public static function withdraw(Partner $partner, User $user): void
    {
        $team = self::memberTeam($user);
        if ($team !== null) {
            DB::table('partner_endorsements')->where(['partner_id' => $partner->id, 'team_id' => $team])->delete();
            self::changed($partner);
        }
    }

    /**
     * Public endorsements: teams with public profiles only.
     *
     * @return list<array{team: string, slug: string, note: ?string, since: string}>
     */
    public static function endorsements(Partner $partner): array
    {
        return array_values(DB::table('partner_endorsements as e')->join('teams as t', 't.id', '=', 'e.team_id')->where('e.partner_id', $partner->id)->where('t.profile_public', true)
            ->orderBy('e.id')->get(['t.name', 't.slug', 'e.note', 'e.created_at'])
            ->map(fn ($r) => ['team' => (string) $r->name, 'slug' => (string) $r->slug, 'note' => $r->note !== null ? (string) $r->note : null, 'since' => substr((string) $r->created_at, 0, 10)])->all());
    }

    /** Pages that show this partner: its own page, its category pages and the city pages it appears on. */
    public static function changed(Partner $partner): void
    {
        $paths = [$partner->path(), '/partners'];
        $industries = Directory::groups()->map(fn ($g) => $g->industry->slug)->unique();
        foreach (PartnerListing::query()->with('city.state')->where('partner_id', $partner->id)->get() as $l) {
            $state = strtolower((string) $l->city->state->code);
            $paths[] = '/partners/'.$partner->category->slug.'/'.$state.'/'.$l->city->slug;
            foreach ($industries as $slug) {
                $paths[] = '/industries/'.$slug.'/'.$state.'/'.$l->city->slug;
            }
        }
        PublicUrlsChanged::dispatch(array_values(array_unique($paths)));
    }

    /**
     * Staff who review partners.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public static function staff(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()->where(fn ($q) => $q->whereHas('permissions', fn ($p) => $p->where('name', 'partners.manage'))
            ->orWhereHas('roles', fn ($r) => $r->where('name', Permissions::SUPER_ADMIN)->orWhereHas('permissions', fn ($p) => $p->where('name', 'partners.manage'))))->get();
    }

    /** @param list<string> $lines */
    public static function mail(Partner $partner, string $subject, array $lines, ?string $action = null, ?string $url = null, ?string $replyTo = null): void
    {
        Notification::route('mail', $partner->email)->notify(new PartnerMail($subject, $lines, $action, $url, $replyTo));
    }

    private static function slug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 100, '') ?: 'partner';
        $slug = $base;
        for ($i = 2; Partner::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
