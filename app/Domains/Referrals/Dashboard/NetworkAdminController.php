<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Dashboard;

use App\Domains\Billing\RevenueTypes;
use App\Domains\Identity\Models\User;
use App\Domains\Referrals\Referral;
use App\Domains\Seo\SeoData;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super admin: the network dashboard, revenue by type (with "view revenue" only), and the network CRM. Client names are
 * shortened everywhere; full contact details need a stated reason and are audited.
 */
final class NetworkAdminController
{
    public const PER_PAGE = 50;

    public function dashboard(Request $request): Response
    {
        $p = $this->period($request);
        $canRevenue = $this->user($request)->can('revenue.view');

        return Inertia::render('admin/Network', [
            'period' => $p->toArray(), 'presets' => Period::PRESETS,
            'cards' => NetworkKpis::cards($p), 'trend' => NetworkKpis::trend($p), 'top' => NetworkKpis::top($p),
            'revenue' => $canRevenue ? $this->revenue($p) : null,
            'seo' => SeoData::private('Network dashboard')->toArray(),
        ]);
    }

    public function drill(Request $request, string $kpi): Response
    {
        abort_unless(isset(NetworkKpis::KPIS[$kpi]), 404);
        $p = $this->period($request);

        return Inertia::render('admin/NetworkDrill', [
            'kpi' => ['key' => $kpi, 'label' => NetworkKpis::KPIS[$kpi][0], 'kind' => NetworkKpis::KPIS[$kpi][1], 'value' => NetworkKpis::value($kpi, $p)],
            'rows' => NetworkKpis::rows($kpi, $p), 'period' => $p->toArray(), 'seo' => SeoData::private(NetworkKpis::KPIS[$kpi][0])->toArray(),
        ]);
    }

    public function revenuePage(Request $request): Response
    {
        $p = $this->period($request);
        $invoices = DB::table('invoices')->where('status', 'paid')->whereBetween('paid_at', [$p->from, $p->to])->orderByDesc('paid_at')->limit(200)
            ->get(['number', 'owner_type', 'owner_id', 'total_cents', 'paid_at'])->map(fn ($i) => ['number' => (string) $i->number, 'payer' => self::payer((string) $i->owner_type, (int) $i->owner_id),
                'total' => (int) $i->total_cents, 'paid' => substr((string) $i->paid_at, 0, 10)])->values();

        return Inertia::render('admin/Revenue', ['period' => $p->toArray(), 'presets' => Period::PRESETS, 'revenue' => $this->revenue($p), 'invoices' => $invoices,
            'seo' => SeoData::private('Revenue')->toArray()]);
    }

    public function revenueCsv(Request $request): StreamedResponse
    {
        $p = $this->period($request);
        $r = $this->revenue($p);

        return response()->streamDownload(function () use ($r) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Type', 'This period ($)', 'Previous period ($)']);
            foreach ($r['rows'] as $row) {
                fputcsv($out, [$row['label'], number_format($row['cents'] / 100, 2, '.', ''), number_format($row['previous'] / 100, 2, '.', '')]);
            }
            fclose($out);
        }, 'revenue-'.$p->from->toDateString().'-to-'.$p->to->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    /** The network CRM: every referral, filtered. */
    public function crm(Request $request): Response
    {
        $f = $this->filters($request);
        $q = $this->crmQuery($f);
        $total = (clone $q)->count();
        $page = max(1, (int) $request->query('page', '1'));
        $rows = $q->orderByDesc('r.id')->forPage($page, self::PER_PAGE)->get(['r.ulid', 'r.client_name', 'r.status', 'r.stage', 'r.created_at', 'r.sender_team_id', 'r.receiver_team_id', 'g.name as network', 's.territory_name as city']);
        $teams = DB::table('teams')->pluck('name', 'id');

        return Inertia::render('admin/NetworkCrm', [
            'filters' => $f, 'total' => $total, 'page' => $page, 'pages' => (int) max(1, ceil($total / self::PER_PAGE)),
            'rows' => $rows->map(fn ($r) => ['id' => (string) $r->ulid, 'client' => Referral::shorten((string) $r->client_name), 'network' => (string) $r->network, 'city' => (string) $r->city,
                'sender' => (string) ($teams[$r->sender_team_id] ?? ''), 'receiver' => (string) ($teams[$r->receiver_team_id] ?? ''), 'stage' => (string) ($r->stage ?? $r->status), 'date' => substr((string) $r->created_at, 0, 10)])->values(),
            'options' => ['industries' => DB::table('industries')->orderBy('name')->get(['slug', 'name']), 'groups' => DB::table('groups')->orderBy('name')->get(['slug', 'name']),
                'states' => DB::table('states')->orderBy('code')->pluck('code'), 'stages' => ['sent', 'accepted', 'declined', 'expired', 'won', 'lost']],
            'revealed' => session('revealed'),
            'seo' => SeoData::private('Network CRM')->toArray(),
        ]);
    }

    public function crmCsv(Request $request): StreamedResponse
    {
        $q = $this->crmQuery($this->filters($request));
        $teams = DB::table('teams')->pluck('name', 'id');

        return response()->streamDownload(function () use ($q, $teams) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Referral', 'Date', 'Network', 'City', 'Sender', 'Receiver', 'Stage', 'Client']);
            foreach ($q->orderByDesc('r.id')->limit(10000)->get(['r.ulid', 'r.created_at', 'g.name as network', 's.territory_name as city', 'r.sender_team_id', 'r.receiver_team_id', 'r.stage', 'r.status', 'r.client_name']) as $r) {
                fputcsv($out, [$r->ulid, substr((string) $r->created_at, 0, 10), $r->network, $r->city, $teams[$r->sender_team_id] ?? '', $teams[$r->receiver_team_id] ?? '', $r->stage ?? $r->status, Referral::shorten((string) $r->client_name)]);
            }
            fclose($out);
        }, 'network-referrals.csv', ['Content-Type' => 'text/csv']);
    }

    /** Full client details for one referral, for a support case: a stated reason, and audited. */
    public function reveal(Request $request, string $referral): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:300']], ['reason.min' => 'Say why you need the client’s details (at least 10 characters).']);
        $r = Referral::query()->where('ulid', $referral)->firstOrFail();
        activity('privacy')->causedBy($request->user())->performedOn($r)->event('client-details-viewed')->withProperties(['reason' => $d['reason']])->log('Client details viewed in the network CRM');

        return back()->with('revealed', ['id' => $r->ulid, 'name' => $r->client_name, 'phone' => $r->client_phone, 'email' => $r->client_email]);
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        $keys = ['industry', 'group', 'state', 'city', 'sender', 'receiver', 'stage', 'from', 'to', 'q'];

        return collect($keys)->mapWithKeys(fn ($k) => [$k => mb_substr(trim((string) $request->query($k, '')), 0, 80)])->filter(fn ($v) => $v !== '')->all();
    }

    /** @param array<string, string> $f */
    private function crmQuery(array $f): Builder
    {
        $team = fn (string $name) => DB::table('teams')->where('name', 'like', '%'.$name.'%')->select('id');

        return DB::table('referrals as r')->join('groups as g', 'g.id', '=', 'r.group_id')->join('industries as i', 'i.id', '=', 'g.industry_id')
            ->join('seats as s', 's.id', '=', 'r.seat_id')->join('cities as c', 'c.id', '=', 's.territory_id')->join('states as st', 'st.id', '=', 'c.state_id')
            ->when($f['industry'] ?? null, fn ($q, $v) => $q->where('i.slug', $v))->when($f['group'] ?? null, fn ($q, $v) => $q->where('g.slug', $v))
            ->when($f['state'] ?? null, fn ($q, $v) => $q->where('st.code', strtoupper($v)))->when($f['city'] ?? null, fn ($q, $v) => $q->where('c.name', 'like', '%'.$v.'%'))
            ->when($f['sender'] ?? null, fn ($q, $v) => $q->whereIn('r.sender_team_id', $team($v)))->when($f['receiver'] ?? null, fn ($q, $v) => $q->whereIn('r.receiver_team_id', $team($v)))
            ->when($f['stage'] ?? null, fn ($q, $v) => in_array($v, ['won', 'lost'], true) ? $q->where('r.stage', $v) : $q->where('r.status', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('r.created_at', '>=', $v.' 00:00:00'))->when($f['to'] ?? null, fn ($q, $v) => $q->where('r.created_at', '<=', $v.' 23:59:59'))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where('r.ulid', $v));
    }

    /** @return array{rows: list<array{type: string, label: string, cents: int, previous: int}>, net: int, previousNet: int, refunds: int, tax: int, invoices: int} */
    private function revenue(Period $p): array
    {
        $now = RevenueTypes::summary($p->from->toDateTimeString(), $p->to->toDateTimeString());
        $prev = RevenueTypes::summary($p->previous()->from->toDateTimeString(), $p->previous()->to->toDateTimeString());
        $rows = [];
        foreach (RevenueTypes::LABELS as $type => $label) {
            if ($type !== 'tax') {
                $rows[] = ['type' => $type, 'label' => $label, 'cents' => $now['byType'][$type], 'previous' => $prev['byType'][$type]];
            }
        }
        $rows[] = ['type' => 'refunds', 'label' => 'Refunds', 'cents' => -$now['refunds'], 'previous' => -$prev['refunds']];

        return ['rows' => $rows, 'net' => $now['net'], 'previousNet' => $prev['net'], 'refunds' => $now['refunds'], 'tax' => $now['tax'], 'invoices' => $now['invoices']];
    }

    private static function payer(string $type, int $id): string
    {
        return (string) ($type === 'team' ? DB::table('teams')->where('id', $id)->value('name') : DB::table('companies')->where('id', $id)->value('name'));
    }

    private function period(Request $request): Period
    {
        return Period::from((string) $request->query('preset'), (string) $request->query('from'), (string) $request->query('to'));
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
