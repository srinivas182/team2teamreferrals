<?php

declare(strict_types=1);

namespace App\Domains\Platform\Reports;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Referrals\Dashboard\Period;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use stdClass;
use Throwable;

/**
 * Scheduled reports: weekly or monthly at a chosen hour in the schedule's time zone. Access is checked for each
 * recipient on every run (an owner's report never leaves their network; revenue needs "view revenue"). Up to
 * attachRows() rows go as an attached CSV; larger reports are saved privately and sent as a 7-day download link for
 * that recipient only. Failed sends retry on later runs (3 attempts in all); every attempt is logged.
 */
final class Schedules
{
    /** Rows sent as an attachment; larger reports go as a private link (config reports.attach_rows, default 5,000). */
    public static function attachRows(): int
    {
        return (int) config('reports.attach_rows', 5000);
    }

    public const MAX_ATTEMPTS = 3;

    public static function nextRun(string $frequency, ?int $weekday, ?int $day, int $hour, string $tz, ?CarbonImmutable $after = null): CarbonImmutable
    {
        $now = ($after ?? CarbonImmutable::now())->setTimezone($tz);
        $at = $frequency === 'weekly'
            ? $now->startOfDay()->setHour($hour)->addDays((((int) $weekday - $now->dayOfWeekIso) + 7) % 7)
            : $now->startOfMonth()->setDay(max(1, min(28, (int) $day)))->setHour($hour);
        if ($at->lte($now)) {
            $at = $frequency === 'weekly' ? $at->addWeek() : $at->addMonthNoOverflow();
        }

        return $at->utc();
    }

    /** Every 15 minutes: run what's due, then retry recent failures. */
    public static function runDue(): int
    {
        $sent = 0;
        foreach (DB::table('report_schedules')->where('paused', false)->where('next_run_at', '<=', now())->get() as $s) {
            // Move the schedule on first, so a slow or failing run is never repeated.
            DB::table('report_schedules')->where('id', $s->id)->update(['last_run_at' => now(), 'updated_at' => now(),
                'next_run_at' => self::nextRun((string) $s->frequency, $s->weekday, $s->day, (int) $s->hour, (string) $s->timezone)]);
            foreach ((array) json_decode((string) $s->recipients, true) as $uid) {
                $sent += (int) self::deliver($s, (int) $uid, null);
            }
        }
        foreach (DB::table('report_deliveries')->where('status', 'retrying')->where('attempts', '<', self::MAX_ATTEMPTS)->get() as $d) {
            $s = DB::table('report_schedules')->where('id', $d->schedule_id)->first();
            if ($s !== null && ! $s->paused) {
                $sent += (int) self::deliver($s, (int) $d->user_id, $d);
            }
        }

        return $sent;
    }

    /** Build and send one report to one person; returns whether it was sent. */
    public static function deliver(stdClass $schedule, int $userId, ?stdClass $retry): bool
    {
        $report = DB::table('saved_reports')->where('id', $schedule->report_id)->first();
        $user = User::query()->find($userId);
        $log = function (string $status, int $rows = 0, ?string $error = null) use ($schedule, $userId, $retry) {
            if ($retry !== null) {
                DB::table('report_deliveries')->where('id', $retry->id)->update(['status' => $status, 'attempts' => (int) $retry->attempts + 1, 'rows' => $rows, 'error' => $error, 'updated_at' => now()]);
            } else {
                DB::table('report_deliveries')->insert(['schedule_id' => $schedule->id, 'user_id' => $userId, 'status' => $status, 'attempts' => 1, 'rows' => $rows, 'error' => $error, 'created_at' => now(), 'updated_at' => now()]);
            }
        };
        $group = $report?->group_id ? Group::query()->find((int) $report->group_id) : null;
        if ($report === null || $user === null || ! Datasets::allowed($user, (string) $report->dataset, $group)) {
            $log('skipped', 0, 'No access to this report');

            return false;
        }
        try {
            $period = Period::from($schedule->frequency === 'weekly' ? 'last_7' : 'last_month');
            $result = Datasets::runSaved($report, $group, $period);
            $csv = Datasets::csv($result);
            $link = null;
            if ($result['total'] > self::attachRows()) {
                $link = self::store($csv, $user, (string) $report->ulid);
                $csv = null;
            }
            Mail::to($user->email)->send(new ReportMail((string) $report->name, $period->toArray()['label'].' ('.$period->from->toDateString().' to '.$period->to->toDateString().')', $result, $csv, $link));
            $log('sent', $result['total']);

            return true;
        } catch (Throwable $e) {
            report($e);
            $attempts = $retry !== null ? (int) $retry->attempts + 1 : 1;
            $log($attempts >= self::MAX_ATTEMPTS ? 'failed' : 'retrying', 0, mb_substr($e->getMessage(), 0, 255));

            return false;
        }
    }

    /** Save a large report privately; the link works for 7 days, for this person only. */
    public static function store(string $csv, User $user, string $ulid): string
    {
        $name = $ulid.'-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(4)).'.csv';
        Storage::disk('local')->put('reports/'.$name, $csv);

        return URL::temporarySignedRoute('reports.file', now()->addDays(7), ['file' => $name, 'for' => $user->id]);
    }
}
