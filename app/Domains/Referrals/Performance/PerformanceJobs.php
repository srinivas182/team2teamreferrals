<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Performance;

use App\Domains\Messaging\Notifier;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily: a friendly early warning when a seat holder's score dips below 75 (at most monthly).
 * On the 1st (or with --cards): last month's report card for every seat holder.
 */
final class PerformanceJobs extends Command
{
    public const WARN_BELOW = 75;

    protected $signature = 't2t:performance {--cards : Send last month\'s report cards now}';

    protected $description = 'Early score warnings and seat challenges (daily), and monthly report cards (on the 1st)';

    public function handle(Notifier $notifier): int
    {
        $holders = Team::query()->with('leader')->whereIn('id', DB::table('seats')->whereNotNull('primary_team_id')->pluck('primary_team_id'))->get();
        foreach ($holders as $team) {
            $s = ServiceScore::for($team);
            if ($s['score'] !== null && $s['score'] < self::WARN_BELOW && ($team->score_warned_at === null || CarbonImmutable::parse((string) $team->score_warned_at)->lt(now()->subDays(30)))) {
                $tips = ServiceScore::topTips($s);
                $notifier->send($team->leader, 'network.score', ['headline' => "Your service score is {$s['score']}: here’s what helps most", 'body' => implode(' ', $tips).' Seats stay with teams that keep clients and senders happy.'], url('/app/performance'));
                DB::table('teams')->where('id', $team->id)->update(['score_warned_at' => now()]);
            }
        }
        app(SeatChallenges::class)->daily();
        if ($this->option('cards') || now()->day === 1) {
            $period = now()->subMonthNoOverflow()->format('Y-m');
            foreach ($holders as $team) {
                $this->card($team, $period, $notifier);
            }
        }

        return self::SUCCESS;
    }

    private function card(Team $team, string $period, Notifier $notifier): void
    {
        if (DB::table('report_cards')->where(['team_id' => $team->id, 'period' => $period])->exists()) {
            return;
        }
        $start = CarbonImmutable::parse($period.'-01')->startOfMonth();
        $end = $start->endOfMonth();
        $received = Referral::query()->where('receiver_team_id', $team->id)->whereBetween('created_at', [$start, $end])->get();
        $accepted = $received->whereNotNull('accepted_at');
        $mins = $accepted->map(fn (Referral $r) => $r->created_at->diffInMinutes($r->accepted_at))->filter();
        $score = ServiceScore::for($team, $end);
        $data = [
            'received' => $received->count(), 'accepted' => $accepted->count(), 'won' => $received->filter(fn (Referral $r) => $r->closing_value_cents !== null)->count(),
            'sent' => Referral::query()->where('sender_team_id', $team->id)->whereBetween('created_at', [$start, $end])->count(),
            'avg_accept_minutes' => $mins->isEmpty() ? null : (int) round((float) $mins->avg()),
            'parts' => $score['parts'], 'tips' => ServiceScore::topTips($score),
        ];
        DB::table('report_cards')->insert(['team_id' => $team->id, 'period' => $period, 'score' => $score['score'], 'data' => json_encode($data), 'created_at' => now()]);
        $month = $start->format('F');
        $line = "{$data['received']} received, {$data['accepted']} accepted, {$data['won']} won, {$data['sent']} sent.";
        $notifier->send($team->leader, 'network.report_card', ['headline' => "Your {$month} report card".($score['score'] !== null ? ": service score {$score['score']}" : ''), 'body' => $line.($data['tips'] ? ' To improve: '.$data['tips'][0] : ' Great work.')], url('/app/performance'));
    }
}
