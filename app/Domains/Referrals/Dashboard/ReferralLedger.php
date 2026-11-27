<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Dashboard;

use App\Domains\Identity\Models\User;
use App\Domains\Referrals\Referral;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The referral ledger for admins and group owners: every referral with who sent it (team and person), who received
 * it (team and person), its status and the money: the receiving team's GCI, the referral commission (fee) and the
 * platform's share. Used by Admin → Referrals and a group's Referrals page (list, totals, CSV for any date range).
 *
 * Client names are shortened ("Tom B.") except for super admins; a super admin's export with full names is audited.
 */
final class ReferralLedger
{
    public const FILTERS = ['industry', 'group', 'state', 'city', 'sender', 'receiver', 'stage', 'from', 'to', 'q'];

    public const STAGES = ['sent', 'accepted', 'declined', 'expired', 'won', 'lost'];

    /** @param array<string, string> $f */
    public static function query(array $f, ?int $groupId = null): Builder
    {
        $team = fn (string $name) => DB::table('teams')->where('name', 'like', '%'.$name.'%')->select('id');

        return DB::table('referrals as r')->join('groups as g', 'g.id', '=', 'r.group_id')->join('industries as i', 'i.id', '=', 'g.industry_id')
            ->join('seats as s', 's.id', '=', 'r.seat_id')->leftJoin('cities as c', 'c.id', '=', 's.territory_id')->leftJoin('states as st', 'st.id', '=', 'c.state_id')
            ->leftJoin('referral_fees as f', 'f.referral_id', '=', 'r.id')
            ->when($groupId, fn ($q, $v) => $q->where('r.group_id', $v))
            ->when($f['industry'] ?? null, fn ($q, $v) => $q->where('i.slug', $v))->when($f['group'] ?? null, fn ($q, $v) => $q->where('g.slug', $v))
            ->when($f['state'] ?? null, fn ($q, $v) => $q->where('st.code', strtoupper($v)))->when($f['city'] ?? null, fn ($q, $v) => $q->where('s.territory_name', 'like', '%'.$v.'%'))
            ->when($f['sender'] ?? null, fn ($q, $v) => $q->whereIn('r.sender_team_id', $team($v)))->when($f['receiver'] ?? null, fn ($q, $v) => $q->whereIn('r.receiver_team_id', $team($v)))
            ->when($f['stage'] ?? null, fn ($q, $v) => in_array($v, ['won', 'lost'], true) ? $q->where('r.stage', $v) : $q->where('r.status', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('r.created_at', '>=', $v.' 00:00:00'))->when($f['to'] ?? null, fn ($q, $v) => $q->where('r.created_at', '<=', $v.' 23:59:59'))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where('r.ulid', $v));
    }

    /** @return array{count: int, gci: int, commission: int, platform: int} */
    public static function totals(Builder $q): array
    {
        $t = (clone $q)->selectRaw('count(*) as n, coalesce(sum(r.earnings_cents), 0) as gci, coalesce(sum(f.amount_cents), 0) as commission, coalesce(sum(f.platform_fee_cents), 0) as platform')->first();

        return ['count' => (int) ($t->n ?? 0), 'gci' => (int) ($t->gci ?? 0), 'commission' => (int) ($t->commission ?? 0), 'platform' => (int) ($t->platform ?? 0)];
    }

    /**
     * One page of rows, newest first; S/N counts down the whole filtered list, so row 1 is the oldest.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(Builder $q, int $page, int $perPage, bool $fullNames, int $total): array
    {
        $rows = (clone $q)->orderByDesc('r.id')->forPage($page, $perPage)->get(self::COLUMNS);

        return self::map($rows, $fullNames, $total - ($page - 1) * $perPage);
    }

    public static function csv(Builder $q, bool $fullNames, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($q, $fullNames) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['S/N', 'Date', 'Referral', 'Lead name', 'Network', 'City', 'Referred by (team)', 'Referred by (person)', 'Referred to (team)', 'Referred to (person)', 'Lead status', 'GCI received ($)', 'Referral commission ($)', 'Commission status', 'Platform earnings ($)']);
            $all = (clone $q)->orderByDesc('r.id')->limit(20000)->get(self::COLUMNS);
            $usd = fn (?int $c) => $c === null ? '' : number_format($c / 100, 2, '.', '');
            foreach (self::map($all, $fullNames, $all->count()) as $r) {
                fputcsv($out, [$r['sn'], $r['date'], $r['id'], $r['client'], $r['network'], $r['city'], $r['sender'], $r['senderPerson'], $r['receiver'], $r['receiverPerson'], $r['status'], $usd($r['gci']), $usd($r['commission']), $r['commissionStatus'], $usd($r['platform'])]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** Super admins see full client names; everyone else the short form. */
    public static function fullNames(?User $user): bool
    {
        return $user !== null && $user->hasRole('super_admin');
    }

    private const COLUMNS = ['r.ulid', 'r.created_at', 'r.client_name', 'r.status', 'r.stage', 'r.sender_team_id', 'r.receiver_team_id', 'r.sender_user_id', 'r.receiver_user_id',
        'r.earnings_cents', 'g.name as network', 's.territory_name as city', 'f.amount_cents', 'f.status as fee_status', 'f.platform_fee_cents'];

    /**
     * @param  \Illuminate\Support\Collection<int, stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private static function map(\Illuminate\Support\Collection $rows, bool $fullNames, int $firstSn): array
    {
        $teams = DB::table('teams')->whereIn('id', $rows->pluck('sender_team_id')->merge($rows->pluck('receiver_team_id'))->filter()->unique())->pluck('name', 'id');
        $people = User::query()->whereIn('id', $rows->pluck('sender_user_id')->merge($rows->pluck('receiver_user_id'))->filter()->unique())->get()->keyBy('id');
        $out = [];
        foreach ($rows->values() as $i => $r) {
            $status = $r->stage === 'won' || $r->stage === 'lost' ? (string) $r->stage : (string) $r->status;
            $out[] = [
                'sn' => $firstSn - $i, 'id' => (string) $r->ulid, 'date' => substr((string) $r->created_at, 0, 10),
                'client' => $fullNames ? (string) $r->client_name : Referral::shorten((string) $r->client_name),
                'network' => (string) $r->network, 'city' => (string) $r->city,
                'sender' => (string) ($teams[$r->sender_team_id] ?? ''), 'senderPerson' => (string) ($people->get($r->sender_user_id)->name ?? ''),
                'receiver' => (string) ($teams[$r->receiver_team_id] ?? ''), 'receiverPerson' => (string) ($people->get($r->receiver_user_id)->name ?? ''),
                'status' => $status,
                'gci' => $r->earnings_cents === null ? null : (int) $r->earnings_cents,
                'commission' => $r->amount_cents === null ? null : (int) $r->amount_cents, 'commissionStatus' => (string) ($r->fee_status ?? ''),
                'platform' => $r->platform_fee_cents === null ? null : (int) $r->platform_fee_cents,
            ];
        }

        return $out;
    }
}
