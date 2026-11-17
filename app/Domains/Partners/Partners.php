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
        self::mail($partner, 'Your listing is live', ["{$partner->business_name} is now listed in the partner directory.", 'Enquiries from your listing come straight to this email address. They are yours alone: we never sell or share them.'],
            'See your listing', url($partner->path()));
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
        self::changed($partner);
    }

    public static function reinstate(Partner $partner, User $by): void
    {
        $partner->forceFill(['status' => 'approved', 'status_reason' => null, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
        activity('partners')->causedBy($by)->performedOn($partner)->event('partner-reinstated')->log("Reinstated partner {$partner->business_name}");
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
            ->whereHas('partner', fn ($q) => $q->where('status', 'approved'))
            ->whereIn('category_id', PartnerCategory::query()->where('is_active', true)->select('id'))
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
        abort_if($team === null || $partner->status !== 'approved', 403, 'Only network members can endorse partners.');
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
