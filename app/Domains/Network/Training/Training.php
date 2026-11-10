<?php

declare(strict_types=1);

namespace App\Domains\Network\Training;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Network training and the training gate. When a group requires training (owner switch, or locked on by the
 * platform for the industry), members who haven't passed can't send or receive NEW referrals once the grace
 * period has ended. Work already in progress always continues, and new referrals go to the backup team meanwhile.
 */
final class Training
{
    public function module(Industry $industry): stdClass
    {
        $row = DB::table('training_modules')->where('industry_id', $industry->id)->first();
        if ($row === null) {
            $d = TrainingDefaults::for($industry);
            DB::table('training_modules')->insert(['industry_id' => $industry->id, 'lessons' => json_encode($d['lessons']), 'questions' => json_encode($d['questions']), 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('training_modules')->where('industry_id', $industry->id)->first();
        }

        /** @var stdClass $row */
        return $row;
    }

    public function progress(User $user, stdClass $module): ?stdClass
    {
        /** @var ?stdClass $p */
        $p = DB::table('training_progress')->where(['user_id' => $user->id, 'module_id' => $module->id])->first();

        return $p;
    }

    public function passed(User $user, Industry $industry): bool
    {
        $m = $this->module($industry);
        $p = $this->progress($user, $m);

        return $p !== null && $p->passed_version !== null && (int) $p->passed_version >= (int) $m->min_version;
    }

    public static function required(Group $group): bool
    {
        return $group->industry->training_locked || $group->rule('training_required') === true;
    }

    /** When blocking starts: the switch-on date (or the group's start) plus the grace period. */
    public static function enforcedFrom(Group $group): CarbonImmutable
    {
        $since = $group->rule('training_since');
        $start = is_string($since) ? CarbonImmutable::parse($since) : CarbonImmutable::parse((string) $group->created_at);

        return $start->addDays((int) $group->rule('training_grace_days'));
    }

    public function blocks(User $user, Group $group): bool
    {
        return self::required($group) && now()->greaterThanOrEqualTo(self::enforcedFrom($group)) && ! $this->passed($user, $group->industry);
    }

    /** Who receives a new referral for a seat: the holder, or the backup while the holder isn't trained (or is inactive). */
    public function receiver(Seat $seat): ?Team
    {
        $team = $seat->receiver();
        // The holder is away (approved time off) or hasn't finished required training: the backup receives instead.
        if ($team !== null && $team->id === $seat->primary_team_id && ($this->blocks($team->leader, $seat->group) || \App\Domains\Referrals\Performance\ServiceScore::onTimeOff($team))) {
            $backup = $seat->backup;

            return $backup !== null && ! $this->blocks($backup->leader, $seat->group) && ! \App\Domains\Referrals\Performance\ServiceScore::onTimeOff($backup) ? $backup : null;
        }

        return $team;
    }

    public function completeLesson(User $user, stdClass $module, int $index): void
    {
        $p = $this->progress($user, $module);
        $done = $p ? (array) json_decode((string) $p->lessons_done, true) : [];
        $done = array_values(array_unique([...$done, $index]));
        DB::table('training_progress')->updateOrInsert(['user_id' => $user->id, 'module_id' => $module->id], ['lessons_done' => json_encode($done), 'updated_at' => now(), 'created_at' => $p->created_at ?? now()]);
    }

    /**
     * Mark the quiz. Retakes only ask the questions missed last time; the score is over the whole quiz.
     *
     * @param  array<int|string, mixed>  $answers  question index => chosen option
     * @return array{score: int, passed: bool, wrong: list<array{index: int, why: string}>}
     */
    public function submit(User $user, stdClass $module, array $answers): array
    {
        /** @var list<array{q: string, options: list<string>, answer: int, why: string}> $qs */
        $qs = json_decode((string) $module->questions, true);
        $p = $this->progress($user, $module);
        $asked = $p && $p->missed ? array_map('intval', (array) json_decode((string) $p->missed, true)) : array_keys($qs);
        $wrong = [];
        foreach ($asked as $i) {
            if (! isset($answers[$i]) || (int) $answers[$i] !== $qs[$i]['answer']) {
                $wrong[] = ['index' => $i, 'why' => $qs[$i]['why']];
            }
        }
        $score = (int) round(100 * (count($qs) - count($wrong)) / max(1, count($qs)));
        $passed = $score >= (int) $module->pass_mark;
        DB::table('training_progress')->updateOrInsert(['user_id' => $user->id, 'module_id' => $module->id], [
            'best_score' => max($score, (int) ($p->best_score ?? 0)), 'attempts' => (int) ($p->attempts ?? 0) + 1,
            'missed' => $wrong === [] ? null : json_encode(array_column($wrong, 'index')),
            'passed_version' => $passed ? (int) $module->version : ($p->passed_version ?? null), 'passed_at' => $passed ? now() : ($p->passed_at ?? null),
            'updated_at' => now(), 'created_at' => $p->created_at ?? now(),
        ]);

        return ['score' => $score, 'passed' => $passed, 'wrong' => $wrong];
    }

    /** "Network trained": every member of the team has passed the training for every industry the team works in. */
    public function teamTrained(Team $team): bool
    {
        $industries = $this->industriesFor($team->leader);
        if ($industries === []) {
            return false;
        }
        foreach ($team->members()->get() as $m) {
            foreach ($industries as $i) {
                if (! $this->passed($m, $i)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Industries this person's team holds, backs up, waits for or applied to: the trainings that matter to them. */
    /** @return list<Industry> */
    public function industriesFor(User $user): array
    {
        $team = Team::of($user);
        if ($team === null) {
            return [];
        }
        $ids = DB::table('seat_applications')->join('groups', 'groups.id', '=', 'seat_applications.group_id')->where('seat_applications.team_id', $team->id)->pluck('groups.industry_id')->unique();

        return array_values(Industry::query()->whereIn('id', $ids)->orderBy('name')->get()->all());
    }
}
