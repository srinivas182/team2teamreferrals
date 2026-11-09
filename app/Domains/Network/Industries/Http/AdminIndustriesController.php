<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Compliance;
use App\Domains\Network\Industries\LicensingCsv;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Models\IndustryLicensing;
use App\Domains\Network\Industries\Models\PlatformFeeRule;
use App\Domains\Network\Industries\Territory;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Locations\Models\State;
use App\Domains\Seo\SeoData;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin → Industries: the industry builder, licensing coverage, compliance gate and platform-fee lock. */
final class AdminIndustriesController
{
    public function __construct(private readonly Compliance $compliance) {}

    public function index(): Response
    {
        $states = State::query()->where('is_enabled', true)->count();

        return Inertia::render('admin/industries/Index', [
            'industries' => Industry::query()->withCount('licensing')->orderBy('name')->get()->map(fn (Industry $i) => [
                'slug' => $i->slug, 'name' => $i->name, 'family' => Industry::FAMILIES[$i->family] ?? $i->family, 'tier' => $i->tier->value,
                'territory' => Industry::TERRITORIES[$i->territory_unit] ?? $i->territory_unit, 'status' => $i->status, 'approved' => $i->isApproved(),
                'coverage' => $i->license_required ? ['done' => (int) $i->getAttribute('licensing_count'), 'of' => $states] : null,
            ]),
            'families' => Industry::FAMILIES,
            'territories' => Industry::TERRITORIES,
            'status' => session('status'),
            'seo' => SeoData::private('Industries')->toArray(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateBasics($request, null);
        $industry = Industry::create([...$data, 'slug' => Str::slug($data['name']), 'tier' => Tier::from($data['tier'])]);
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('industry-created')->log("Created industry {$industry->name}");

        return redirect()->route('admin.industries.show', $industry)->with('status', 'industry-created');
    }

    public function show(Industry $industry): Response
    {
        $rules = $industry->licensing()->get()->keyBy('state_id');
        $fee = PlatformFeeRule::query()->where(['industry_id' => $industry->id, 'group_id' => null])->first();

        return Inertia::render('admin/industries/Show', [
            'industry' => [
                'slug' => $industry->slug, 'name' => $industry->name, 'family' => $industry->family, 'tier' => $industry->tier->value, 'territory' => $industry->territory_unit,
                'terminology' => $industry->terms(), 'icon' => $industry->icon, 'description' => $industry->description, 'licenseRequired' => $industry->license_required,
                'status' => $industry->status, 'approved' => $industry->isApproved(), 'approvedAt' => $industry->compliance_approved_at?->toDayDateTimeString(),
                'approvedBy' => $industry->approver?->name, 'note' => $industry->compliance_note,
            ],
            'tierRules' => collect(Tier::cases())->mapWithKeys(fn (Tier $t) => [$t->value => ['label' => $t->label(), 'rules' => $t->rules()]]),
            'territoryPreview' => Territory::preview(),
            'coverage' => State::query()->where('is_enabled', true)->orderBy('code')->get()->map(fn (State $s) => [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->name,
                'rule' => ($r = $rules->get($s->id)) instanceof IndustryLicensing ? ['required' => $r->required, 'issuingBody' => $r->issuing_body, 'pattern' => $r->number_pattern, 'lookupUrl' => $r->lookup_url, 'notes' => $r->notes] : null,
            ]),
            'fee' => ['mode' => $fee->mode ?? 'none', 'percent' => $fee?->percent, 'flatCents' => $fee?->flat_cents, 'chargeAt' => $fee->charge_at ?? 'closing', 'enabled' => (bool) $fee?->enabled],
            'feesUnlocked' => $this->compliance->feesUnlocked(),
            'families' => Industry::FAMILIES,
            'territories' => Industry::TERRITORIES,
            'isSuperAdmin' => request()->user()?->hasRole('super_admin') ?? false,
            'status' => session('status'),
            'seo' => SeoData::private($industry->name)->toArray(),
        ]);
    }

    public function update(Request $request, Industry $industry): RedirectResponse
    {
        $data = $this->validateBasics($request, $industry);
        $tier = Tier::from($data['tier']);
        unset($data['tier']);
        $industry->fill($data)->save();
        $this->compliance->changeTier($industry, $tier, $this->user($request));
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('industry-updated')->log("Updated industry {$industry->name}");

        return back()->with('status', 'industry-saved');
    }

    public function saveLicensing(Request $request, Industry $industry, State $state): RedirectResponse
    {
        $data = $request->validate([
            'required' => ['required', 'boolean'],
            'issuing_body' => ['nullable', 'string', 'max:160'],
            'number_pattern' => ['nullable', 'string', 'max:120', function ($attr, $value, $fail) {
                if ($value !== null && @preg_match('/^(?:'.$value.')$/', '') === false) {
                    $fail('That pattern is not a valid regular expression.');
                }
            }],
            'lookup_url' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        // Saving a row from the builder is the admin's review: starter suggestions stop being "needs review".
        IndustryLicensing::query()->updateOrCreate(['industry_id' => $industry->id, 'state_id' => $state->id], [...$data, 'needs_review' => false]);
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('licensing-saved')->withProperties(['state' => $state->code])->log("Licensing for {$industry->name} in {$state->code}");

        return back()->with('status', 'licensing-saved');
    }

    public function exportLicensing(Industry $industry): StreamedResponse
    {
        return response()->streamDownload(fn () => print (LicensingCsv::export($industry)), "licensing-{$industry->slug}.csv", ['Content-Type' => 'text/csv']);
    }

    public function exportAllLicensing(): StreamedResponse
    {
        return response()->streamDownload(fn () => print (LicensingCsv::export()), 'licensing-all-industries.csv', ['Content-Type' => 'text/csv']);
    }

    public function importLicensing(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimetypes:text/csv,text/plain,application/vnd.ms-excel', 'max:2048']]);
        $result = LicensingCsv::import((string) file_get_contents((string) $request->file('file')?->getRealPath()));
        activity('admin')->causedBy($request->user())->event('licensing-imported')->withProperties($result)->log("Imported licensing rules ({$result['updated']} rows)");

        return back()->with('status', 'licensing-imported')->with('importResult', $result);
    }

    public function approve(Request $request, Industry $industry): RedirectResponse
    {
        $this->superAdminOnly($request);
        $note = (string) $request->validate(['note' => ['required', 'string', 'min:10', 'max:2000']], ['note.min' => 'Record who signed off and on what (for example the counsel and memo date).'])['note'];
        $this->compliance->approve($industry, $this->user($request), $note);

        return back()->with('status', 'industry-approved');
    }

    public function revoke(Request $request, Industry $industry): RedirectResponse
    {
        $this->superAdminOnly($request);
        $this->compliance->revoke($industry, $this->user($request), (string) $request->input('reason', 'Revoked from the admin'));

        return back()->with('status', 'industry-revoked');
    }

    public function status(Request $request, Industry $industry): RedirectResponse
    {
        try {
            $request->boolean('live') ? $this->compliance->goLive($industry, $this->user($request)) : $this->compliance->takeOffline($industry, $this->user($request));
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('status', $request->boolean('live') ? 'industry-live' : 'industry-offline');
    }

    public function saveFee(Request $request, Industry $industry): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['none', 'percent', 'flat'])],
            'percent' => ['nullable', 'required_if:mode,percent', 'numeric', 'min:0.1', 'max:50'],
            'flat_cents' => ['nullable', 'required_if:mode,flat', 'integer', 'min:100', 'max:1000000'],
            'charge_at' => ['required', Rule::in(['acceptance', 'closing'])],
            'enabled' => ['required', 'boolean'],
        ]);
        try {
            $this->compliance->saveFeeRule($industry, null, ['mode' => $data['mode'], 'percent' => $data['percent'] ?? null, 'flat_cents' => $data['flat_cents'] ?? null, 'charge_at' => $data['charge_at']], (bool) $data['enabled'], $this->user($request));
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['enabled' => $e->getMessage()]);
        }

        return back()->with('status', 'fee-saved');
    }

    public function unlockFees(Request $request): RedirectResponse
    {
        $this->superAdminOnly($request);
        $confirmation = (string) $request->validate(['confirmation' => ['required', 'string', 'min:20', 'max:2000']], ['confirmation.min' => 'Describe the licenses and fund routing that are in place.'])['confirmation'];
        $this->compliance->unlockFees($this->user($request), $confirmation);

        return back()->with('status', 'fees-unlocked');
    }

    public function lockFees(Request $request): RedirectResponse
    {
        $this->superAdminOnly($request);
        $this->compliance->lockFees($this->user($request));

        return back()->with('status', 'fees-locked');
    }

    /** @return array{name: string, family: string, tier: string, territory_unit: string, terminology: array{pro: string, pros: string, client: string, clients: string, referral?: ?string, fee?: ?string}, icon: string, description: ?string, license_required: bool} */
    private function validateBasics(Request $request, ?Industry $industry): array
    {
        /** @var array{name: string, family: string, tier: string, territory_unit: string, terminology: array{pro: string, pros: string, client: string, clients: string, referral?: ?string, fee?: ?string}, icon: string, description: ?string, license_required: bool} */
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('industries', 'name')->ignore($industry?->id)],
            'family' => ['required', Rule::in(array_keys(Industry::FAMILIES))],
            'tier' => ['required', new Enum(Tier::class)],
            'territory_unit' => ['required', Rule::in(array_keys(Industry::TERRITORIES))],
            'terminology.pro' => ['required', 'string', 'max:40'],
            'terminology.pros' => ['required', 'string', 'max:40'],
            'terminology.client' => ['required', 'string', 'max:40'],
            'terminology.clients' => ['required', 'string', 'max:40'],
            'terminology.referral' => ['nullable', 'string', 'max:40'],
            'terminology.fee' => ['nullable', 'string', 'max:40'],
            'icon' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9]+$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'license_required' => ['required', 'boolean'],
        ]);
    }

    private function superAdminOnly(Request $request): void
    {
        abort_unless((bool) $request->user()?->hasRole('super_admin'), 403, 'Only a super admin can record legal sign-off.');
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
