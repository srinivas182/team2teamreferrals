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
                'other' => (int) $f->payee_team_id === $team->id ? (string) $f->payer : (string) $f->payee, 'amount' => $f->amount_cents / 100, 'due' => (string) $f->due_on, 'status' => (string) $f->status,
                'reference' => $f->paid_reference]),
            'totals' => [
                'toPay' => $sum($rows->where('payer_team_id', $team->id)->where('status', 'due')) / 100,
                'toReceive' => $sum($rows->where('payee_team_id', $team->id)->whereIn('status', ['due', 'paid'])) / 100,
                'receivedThisYear' => $sum($rows->where('payee_team_id', $team->id)->where('status', 'received')->filter(fn ($f) => str_starts_with((string) $f->received_at, (string) now()->year))) / 100,
            ],
            'canAct' => in_array($team->roleOf($this->user($request)), ['leader', 'assistant'], true),
            'status' => session('status'),
            'seo' => SeoData::private('Earnings')->toArray(),
        ]);
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
        $month = (string) $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];

        return response(Fees::csv($team, $month), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"referral-fees-{$month}.csv\""]);
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
