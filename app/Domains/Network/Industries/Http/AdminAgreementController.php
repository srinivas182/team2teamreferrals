<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Http;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Agreements\AgreementDefaults;
use App\Domains\Network\Industries\Agreements\AgreementRenderer;
use App\Domains\Network\Industries\Agreements\Agreements;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Locations\Models\State;
use App\Domains\Seo\SeoData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Industries → Agreement: wording, approval, state addenda, PDF, compare and the counsel review pack. */
final class AdminAgreementController
{
    public function __construct(private readonly Agreements $agreements) {}

    public function show(Request $request, Industry $industry): Response
    {
        return $this->render($request, $industry, null);
    }

    public function groupShow(Request $request, Group $group): Response
    {
        return $this->render($request, $group->industry, $group);
    }

    public function groupSave(Request $request, Group $group): RedirectResponse
    {
        $request->validate(['clauses' => ['present', 'array']]);
        $version = $this->agreements->save($group->industry, (array) $request->input('clauses'), $request->user()?->getKey(), $group->id);
        activity('admin')->causedBy($request->user())->performedOn($group)->event('agreement-saved')->withProperties(['version' => $version])->log("Saved {$group->name} agreement v{$version} (awaiting approval)");

        return back()->with('status', 'agreement-saved');
    }

    public function groupApprove(Request $request, Group $group): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasRole('super_admin'), 403, 'Only a super admin can approve an agreement.');
        $data = $request->validate(['counsel' => ['required', 'string', 'max:160'], 'note' => ['required', 'string', 'max:2000']]);
        $version = $this->agreements->approve($group->industry, $request->user()->getKey(), (string) $data['counsel'], (string) $data['note'], $group->id);
        activity('admin')->causedBy($request->user())->performedOn($group)->event('agreement-approved')->withProperties(['version' => $version, 'counsel' => $data['counsel']])->log("Approved {$group->name} agreement v{$version}");

        return back()->with('status', 'agreement-approved');
    }

    public function groupReset(Request $request, Group $group): RedirectResponse
    {
        $this->agreements->reset($group->industry, $group->id);
        activity('admin')->causedBy($request->user())->performedOn($group)->event('agreement-reset')->log("{$group->name} now uses its industry's agreement");

        return back()->with('status', 'agreement-reset');
    }

    private function render(Request $request, Industry $industry, ?Group $group): Response
    {
        $gid = $group?->id;
        $current = $this->agreements->current($industry, $gid);
        $history = $this->agreements->history($industry, $gid);
        $compare = null;
        if ($request->filled(['a', 'b'])) {
            $compare = AgreementRenderer::compare($this->agreements->version($industry, $request->integer('a'), $gid), $this->agreements->version($industry, $request->integer('b'), $gid));
        }

        return Inertia::render('admin/industries/Agreement', [
            'industry' => ['slug' => $industry->slug, 'name' => $industry->name, 'tier' => $industry->tier->value, 'tierLabel' => $industry->tier->label(), 'allowsFees' => $industry->tier->allowsMemberReferralFees(), 'terminology' => $industry->terms()],
            'current' => $current,
            'signing' => $this->agreements->forSigning($industry, $gid)['version'] ?? null,
            'signingScope' => $this->agreements->forSigning($industry, $gid)['scope'] ?? null,
            'scope' => $group === null
                ? ['basePath' => "/admin/industries/{$industry->slug}/agreement", 'backHref' => "/admin/industries/{$industry->slug}", 'backLabel' => $industry->name, 'group' => null]
                : ['basePath' => "/admin/groups/{$group->slug}/agreement", 'backHref' => "/admin/groups/{$group->slug}", 'backLabel' => $group->name, 'group' => $group->name],
            'history' => $history,
            'fields' => AgreementDefaults::FIELDS,
            'feeFields' => AgreementDefaults::FEE_FIELDS,
            'defaults' => AgreementDefaults::for($industry->tier),
            'sample' => $this->sample($industry),
            'addenda' => $this->addenda($industry),
            'states' => State::query()->orderBy('name')->get(['id', 'name', 'code'])->map(fn (State $s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code]),
            'compare' => $compare,
            'compareVersions' => $request->filled(['a', 'b']) ? ['a' => $request->integer('a'), 'b' => $request->integer('b')] : null,
            'status' => session('status'),
            'seo' => SeoData::private(($group->name ?? $industry->name).' agreement')->toArray(),
        ]);
    }

    public function save(Request $request, Industry $industry): RedirectResponse
    {
        $request->validate(['clauses' => ['present', 'array']]);
        $version = $this->agreements->save($industry, (array) $request->input('clauses'), $request->user()?->getKey());
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('agreement-saved')->withProperties(['version' => $version])->log("Saved {$industry->name} agreement v{$version} (awaiting approval)");

        return back()->with('status', 'agreement-saved');
    }

    public function approve(Request $request, Industry $industry): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasRole('super_admin'), 403, 'Only a super admin can approve an agreement.');
        $data = $request->validate(['counsel' => ['required', 'string', 'max:160'], 'note' => ['required', 'string', 'max:2000']], ['counsel.required' => 'Record who reviewed it (e.g. the attorney and firm).']);
        $version = $this->agreements->approve($industry, $request->user()->getKey(), (string) $data['counsel'], (string) $data['note']);
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('agreement-approved')->withProperties(['version' => $version, 'counsel' => $data['counsel']])->log("Approved {$industry->name} agreement v{$version}");

        return back()->with('status', 'agreement-approved');
    }

    public function reset(Request $request, Industry $industry): RedirectResponse
    {
        $this->agreements->reset($industry);
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('agreement-reset')->log("Reset {$industry->name} agreement draft to the default wording");

        return back()->with('status', 'agreement-reset');
    }

    public function saveAddendum(Request $request, Industry $industry, State $state): RedirectResponse
    {
        $text = trim((string) $request->validate(['text' => ['nullable', 'string', 'max:4000']])['text']);
        if ($text === '') {
            DB::table('agreement_state_addenda')->where(['industry_id' => $industry->id, 'state_id' => $state->id])->delete();
        } else {
            DB::table('agreement_state_addenda')->updateOrInsert(['industry_id' => $industry->id, 'state_id' => $state->id], ['text' => $text, 'updated_by' => $request->user()?->getKey(), 'updated_at' => now(), 'created_at' => now()]);
        }
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('agreement-addendum')->withProperties(['state' => $state->code, 'removed' => $text === ''])->log("Updated {$industry->name} {$state->name} addendum");

        return back()->with('status', 'addendum-saved');
    }

    public function pdf(Request $request, Industry $industry): HttpResponse
    {
        $clauses = $request->filled('version') ? $this->agreements->version($industry, $request->integer('version')) : $this->agreements->current($industry)['clauses'];
        $data = $this->sample($industry);
        $addenda = collect($this->addenda($industry))->where('code', 'TX')->map(fn ($a) => ['state' => $a['name'], 'text' => $a['text']])->values()->all();

        return Pdf::loadView('pdf.agreement', [
            'title' => 'Referral agreement (preview)', 'industry' => $industry, 'sections' => AgreementRenderer::render($clauses, $data, $addenda),
            'summary' => AgreementRenderer::summary($clauses, $data), 'watermark' => 'SAMPLE · NOT FOR SIGNING',
        ])->stream("{$industry->slug}-agreement-preview.pdf");
    }

    /** One PDF for the attorney: current wording, plain-English summary, every state addendum and the approval history. */
    public function counselPack(Request $request, Industry $industry): HttpResponse
    {
        $current = $this->agreements->current($industry);
        $data = $this->sample($industry);
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('counsel-pack')->log("Downloaded the {$industry->name} counsel review pack");

        return Pdf::loadView('pdf.counsel-pack', [
            'industry' => $industry, 'version' => $current['version'], 'approved' => $current['approved'],
            'sections' => AgreementRenderer::render($current['clauses'], $data), 'summary' => AgreementRenderer::summary($current['clauses'], $data),
            'addenda' => $this->addenda($industry), 'history' => $this->agreements->history($industry), 'generated' => now()->toDayDateTimeString(),
            'messages' => AdminIndustryMessagesController::pack($industry, app(\App\Domains\Messaging\Templates::class)), 'terms' => $industry->terms(),
        ])->download("{$industry->slug}-counsel-review-pack.pdf");
    }

    /** One PDF for every industry in a tier, so counsel can review similar industries in one sitting. */
    public function tierPack(Request $request, string $tier): HttpResponse
    {
        $t = Tier::tryFrom(strtoupper($tier));
        abort_if($t === null, 404);
        $industries = Industry::query()->where('tier', $t->value)->orderBy('name')->get()->map(function (Industry $i) use ($t) {
            $current = $this->agreements->current($i);
            $data = $this->sample($i);

            return [
                'industry' => $i, 'terms' => $i->terms(), 'version' => $current['version'], 'approved' => $current['approved'],
                'sections' => AgreementRenderer::render($current['clauses'], $data), 'summary' => $t === Tier::Directory ? [] : AgreementRenderer::summary($current['clauses'], $data),
                'addenda' => $this->addenda($i), 'messages' => AdminIndustryMessagesController::pack($i, app(\App\Domains\Messaging\Templates::class)),
            ];
        });
        activity('admin')->causedBy($request->user())->event('counsel-pack')->withProperties(['tier' => $t->value, 'industries' => $industries->count()])->log("Downloaded the Tier {$t->value} counsel review pack");

        return Pdf::loadView('pdf.counsel-pack-tier', ['tier' => $t, 'industries' => $industries, 'generated' => now()->toDayDateTimeString()])
            ->download('tier-'.strtolower($t->value).'-counsel-review-pack.pdf');
    }

    /** @return array<string, string> */
    private function sample(Industry $industry): array
    {
        return [...AgreementDefaults::sample(), ...$industry->mergeTerms()];
    }

    /** @return array<int, array{stateId: int, code: string, name: string, text: string}> */
    private function addenda(Industry $industry): array
    {
        return DB::table('agreement_state_addenda')->join('states', 'states.id', '=', 'agreement_state_addenda.state_id')
            ->where('industry_id', $industry->id)->orderBy('states.name')->get(['states.id as state_id', 'states.code', 'states.name', 'agreement_state_addenda.text'])
            ->map(fn ($r) => ['stateId' => (int) $r->state_id, 'code' => (string) $r->code, 'name' => (string) $r->name, 'text' => (string) $r->text])->values()->all();
    }
}
