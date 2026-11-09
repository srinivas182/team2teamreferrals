<?php

declare(strict_types=1);

namespace App\Domains\Network\Licenses;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Files\PrivateFiles;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class LicenseController
{
    public function __construct(private readonly Licenses $licenses) {}

    public function mine(Request $request): Response
    {
        $team = $this->team($request);

        return Inertia::render('app/Licenses', [
            'licenses' => DB::table('team_licenses')->join('states', 'states.id', '=', 'team_licenses.state_id')->join('industries', 'industries.id', '=', 'team_licenses.industry_id')
                ->where('team_id', $team->id)->orderBy('states.code')->get(['team_licenses.id', 'team_licenses.number', 'team_licenses.status', 'team_licenses.expires_on', 'team_licenses.review_note', 'states.code', 'industries.name as industry'])
                ->map(fn ($l) => ['id' => (int) $l->id, 'number' => (string) $l->number, 'status' => (string) $l->status, 'expires' => $l->expires_on, 'note' => $l->review_note, 'state' => (string) $l->code, 'industry' => (string) $l->industry]),
            'industries' => Industry::query()->where('license_required', true)->orderBy('name')->get(['id', 'name'])->map(fn (Industry $i) => ['id' => $i->id, 'name' => $i->name]),
            'states' => DB::table('states')->where('is_enabled', true)->orderBy('name')->get(['id', 'code', 'name'])->map(fn ($s) => ['id' => (int) $s->id, 'code' => (string) $s->code, 'name' => (string) $s->name]),
            'canEdit' => in_array($team->roleOf($this->user($request)), ['leader', 'assistant'], true),
            'status' => session('status'),
            'seo' => SeoData::private('Licenses')->toArray(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $this->team($request, true);
        $data = $request->validate([
            'industry_id' => ['required', 'integer', 'exists:industries,id'],
            'state_id' => ['required', 'integer', 'exists:states,id'],
            'number' => ['required', 'string', 'max:60'],
            'expires_on' => ['nullable', 'date', 'after:today'],
            'holder_name' => ['nullable', 'string', 'max:160'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192'],
        ]);
        $this->licenses->add($team, Industry::query()->findOrFail((int) $data['industry_id']), (int) $data['state_id'], (string) $data['number'], $data['expires_on'] ?? null, $data['holder_name'] ?? null, $request->file('document'));
        Licenses::rerank($team);

        return back()->with('status', 'license-added');
    }

    public function destroy(Request $request, int $license): RedirectResponse
    {
        $team = $this->team($request, true);
        DB::table('team_licenses')->where(['id' => $license, 'team_id' => $team->id])->where('status', '!=', 'verified')->delete();
        Licenses::rerank($team);

        return back()->with('status', 'license-removed');
    }

    /** Staff verification queue. */
    public function queue(Request $request): Response
    {
        $status = in_array($request->query('status'), ['pending', 'verified', 'rejected', 'expired'], true) ? (string) $request->query('status') : 'pending';
        $rows = DB::table('team_licenses')->join('teams', 'teams.id', '=', 'team_licenses.team_id')->join('states', 'states.id', '=', 'team_licenses.state_id')->join('industries', 'industries.id', '=', 'team_licenses.industry_id')
            ->leftJoin('industry_licensing', fn ($j) => $j->on('industry_licensing.industry_id', '=', 'team_licenses.industry_id')->on('industry_licensing.state_id', '=', 'team_licenses.state_id'))
            ->where('team_licenses.status', $status)->orderBy('team_licenses.created_at')->limit(200)
            ->get(['team_licenses.*', 'teams.name as team', 'states.code', 'industries.name as industry', 'industry_licensing.lookup_url', 'industry_licensing.issuing_body']);

        return Inertia::render('admin/Licenses', [
            'status' => $status,
            'counts' => DB::table('team_licenses')->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
            'rows' => $rows->map(fn ($l) => [
                'id' => (int) $l->id, 'team' => (string) $l->team, 'industry' => (string) $l->industry, 'state' => (string) $l->code, 'number' => (string) $l->number, 'holder' => $l->holder_name,
                'expires' => $l->expires_on, 'submitted' => (string) $l->created_at, 'note' => $l->review_note, 'issuingBody' => $l->issuing_body, 'lookupUrl' => $l->lookup_url,
                'document' => $l->document_path ? PrivateFiles::url((string) $l->document_path, 15) : null,
                'duplicates' => Licenses::duplicates((int) $l->state_id, (string) $l->number, (int) $l->team_id),
            ]),
            'flash' => session('status'),
            'seo' => SeoData::private('License queue')->toArray(),
        ]);
    }

    public function decide(Request $request, int $license): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:verify,reject'], 'note' => ['nullable', 'string', 'max:1000']]);
        $this->licenses->decide($license, $this->user($request), $data['decision'] === 'verify', $data['note'] ?? null);

        return back()->with('status', $data['decision'] === 'verify' ? 'license-verified' : 'license-rejected');
    }

    private function team(Request $request, bool $edit = false): Team
    {
        $user = $this->user($request);
        $team = Team::of($user) ?? Team::forLeader($user);
        abort_if($edit && ! in_array($team->roleOf($user), ['leader', 'assistant'], true), 403);

        return $team;
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
