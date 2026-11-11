<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Fees;
use App\Domains\Referrals\Referral;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

final class FeeController
{
    public function __construct(private readonly Fees $fees) {}

    /** Portal → Earnings: referral fees to pay and to receive, statements, and the accountant's export. */
    public function index(Request $request): Response
    {
        $team = Team::of($this->user($request)) ?? Team::forLeader($this->user($request));
        $rows = DB::table('referral_fees as f')->join('referrals as r', 'r.id', '=', 'f.referral_id')->join('teams as p', 'p.id', '=', 'f.payer_team_id')->join('teams as q', 'q.id', '=', 'f.payee_team_id')
            ->where(fn ($w) => $w->where('f.payer_team_id', $team->id)->orWhere('f.payee_team_id', $team->id))->orderByDesc('f.id')->limit(300)
            ->get(['f.*', 'r.ulid', 'r.client_name', 'p.name as payer', 'q.name as payee']);
        $sum = fn ($set) => (int) $set->sum('amount_cents');

        return Inertia::render('app/Earnings', [
            'fees' => $rows->map(fn ($f) => ['id' => (int) $f->id, 'referral' => (string) $f->ulid, 'client' => (string) $f->client_name, 'incoming' => (int) $f->payee_team_id === $team->id,
                'other' => (int) $f->payee_team_id === $team->id ? (string) $f->payer : (string) $f->payee, 'amount' => $f->amount_cents / 100,
                'platformFee' => (int) $f->payee_team_id === $team->id ? $f->platform_fee_cents / 100 : 0, 'basis' => $f->basis_cents !== null ? $f->basis_cents / 100 : null, 'due' => (string) $f->due_on, 'status' => (string) $f->status,
                'reference' => $f->paid_reference]),
            'totals' => [
                'toPay' => $sum($rows->where('payer_team_id', $team->id)->where('status', 'due')) / 100,
                'toReceive' => $sum($rows->where('payee_team_id', $team->id)->whereIn('status', ['due', 'paid'])) / 100,
                // Net of the platform fee: what the referring team actually keeps.
                'receivedThisYear' => ($sum($rows->where('payee_team_id', $team->id)->where('status', 'received')->filter(fn ($f) => str_starts_with((string) $f->received_at, (string) now()->year)))
                    - (int) $rows->where('payee_team_id', $team->id)->where('status', 'received')->filter(fn ($f) => str_starts_with((string) $f->received_at, (string) now()->year))->sum('platform_fee_cents')) / 100,
                'platformOwed' => (int) $rows->where('payee_team_id', $team->id)->where('platform_fee_status', 'owed')->sum('platform_fee_cents') / 100,
            ],
            'dashboard' => self::dashboard($team, $rows),
            'giveGet' => \App\Domains\Referrals\Credits::giveGet($team),
            'canAct' => in_array($team->roleOf($this->user($request)), ['leader', 'assistant'], true),
            'status' => session('status'),
            'seo' => SeoData::private('Earnings')->toArray(),
        ]);
    }

    /**
     * Earnings for the referring side, net of the platform fee: expected, received (this year, all time), overdue,
     * referrals in progress, and breakdowns by network and partner. Computed from the fee records themselves.
     *
     * @param  \Illuminate\Support\Collection<int, stdClass>  $rows
     * @return array<string, mixed>
     */
    private static function dashboard(Team $team, \Illuminate\Support\Collection $rows): array
    {
        $in = $rows->where('payee_team_id', $team->id);
        $net = fn ($set) => ((int) $set->sum('amount_cents') - (int) $set->sum('platform_fee_cents')) / 100;
        $received = $in->where('status', 'received');
        $ytd = $received->filter(fn ($f) => str_starts_with((string) $f->received_at, (string) now()->year));
        $groups = DB::table('referral_fees as f')->join('referrals as r', 'r.id', '=', 'f.referral_id')->join('groups as g', 'g.id', '=', 'r.group_id')
            ->where('f.payee_team_id', $team->id)->whereIn('f.status', ['due', 'paid', 'received'])->groupBy('g.name')
            ->selectRaw("g.name as label, sum(case when f.status = 'received' then f.amount_cents - f.platform_fee_cents else 0 end) as received, sum(case when f.status <> 'received' then f.amount_cents - f.platform_fee_cents else 0 end) as expected")->get();
        $partners = $in->whereIn('status', ['due', 'paid', 'received'])->groupBy('payer')->map(fn ($set, $name) => ['label' => (string) $name,
            'received' => $net($set->where('status', 'received')), 'expected' => $net($set->where('status', '!=', 'received'))])->values();

        return [
            'expected' => $net($in->whereIn('status', ['due', 'paid'])), 'receivedYtd' => $net($ytd), 'receivedAll' => $net($received),
            'overdue' => $net($in->where('status', 'due')->filter(fn ($f) => (string) $f->due_on < now()->toDateString())),
            'inProgress' => Referral::query()->where(['sender_team_id' => $team->id, 'status' => 'accepted'])->whereNull('closed_at')->count(),
            'byGroup' => $groups->map(fn ($g) => ['label' => (string) $g->label, 'received' => (int) $g->received / 100, 'expected' => (int) $g->expected / 100])->values(),
            'byPartner' => $partners,
        ];
    }

    /** Year-end summary PDF for the accountant: earnings by network and partner, and every fee in the year. */
    public function yearPdf(Request $request, int $year): \Illuminate\Http\Response
    {
        $team = Team::of($this->user($request)) ?? Team::forLeader($this->user($request));
        $rows = DB::table('referral_fees as f')->join('referrals as r', 'r.id', '=', 'f.referral_id')->join('teams as p', 'p.id', '=', 'f.payer_team_id')->join('teams as q', 'q.id', '=', 'f.payee_team_id')
            ->where(fn ($w) => $w->where('f.payer_team_id', $team->id)->orWhere('f.payee_team_id', $team->id))->where('f.created_at', 'like', $year.'%')->orderBy('f.created_at')
            ->get(['f.*', 'r.client_name', 'p.name as payer', 'q.name as payee']);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.earnings-year', ['team' => $team, 'year' => $year, 'rows' => $rows])->download("referral-earnings-{$year}.pdf");
    }

    public function statement(Request $request, int $fee): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $f = DB::table('referral_fees')->where('id', $fee)->first();
        $team = Team::of($this->user($request));
        abort_unless($f !== null && $team !== null && in_array($team->id, [(int) $f->payer_team_id, (int) $f->payee_team_id], true) && $f->statement_path, 404);

        return Storage::disk('private')->download((string) $f->statement_path, 'referral-fee-statement.pdf');
    }

    public function paid(Request $request, int $fee): RedirectResponse
    {
        $this->fees->markPaid($fee, $this->user($request), $request->validate(['reference' => ['nullable', 'string', 'max:120']])['reference'] ?? null);

        return back()->with('status', 'fee-paid');
    }

    public function received(Request $request, int $fee): RedirectResponse
    {
        $this->fees->markReceived($fee, $this->user($request));

        return back()->with('status', 'fee-received');
    }

    public function export(Request $request): \Illuminate\Http\Response
    {
        $team = Team::of($this->user($request)) ?? Team::forLeader($this->user($request));
        // A month (YYYY-MM) or a whole year (YYYY) for the year-end export.
        $period = (string) $request->validate(['month' => ['required', 'regex:/^\d{4}(-\d{2})?$/']])['month'];

        return response(Fees::csv($team, $period), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"referral-fees-{$period}.csv\""]);
    }

    public function propose(Request $request, Referral $referral): RedirectResponse
    {
        $d = $request->validate(['type' => ['required', 'in:percent,flat'], 'value' => ['required', 'numeric']]);
        $this->fees->propose($referral, $this->user($request), (string) $d['type'], (float) $d['value']);

        return back()->with('status', 'fee-proposed');
    }

    public function accept(Request $request, Referral $referral): RedirectResponse
    {
        $this->fees->acceptProposal($referral, $this->user($request));

        return back()->with('status', 'fee-agreed');
    }

    /** Group owners (and admins) set the network's default referral fee. */
    public function groupDefault(Request $request, Group $group): RedirectResponse
    {
        $u = $this->user($request);
        abort_unless($group->isOwnedBy($u) || $u->can('groups.manage'), 403);
        $d = $request->validate(['type' => ['nullable', 'in:percent,flat'], 'value' => ['nullable', 'required_with:type', 'numeric'], 'due_days' => ['required', 'integer', 'between:1,90']]);
        $this->fees->setGroupDefault($group, $u, $d['type'] ?? null, isset($d['value']) ? (float) $d['value'] : null, (int) $d['due_days']);

        return back()->with('status', 'fee-default-saved');
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
