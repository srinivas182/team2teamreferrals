<?php

declare(strict_types=1);

namespace App\Domains\Seo\Monitoring;

use App\Domains\Platform\Settings\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** The monthly answer-engine visibility check: queues each active question for each engine that is switched on. */
final class VisibilityCommand extends Command
{
    protected $signature = 't2t:aeo:check {--month= : YYYY-MM (defaults to this month)} {--force : Ask again even if this month is done}';

    protected $description = 'Ask the AI answer engines that are switched on this month\'s questions, and record whether they cite the site or a member';

    public function handle(AnswerEngines $engines, Settings $settings): int
    {
        $on = $engines->enabled();
        if ($on === []) {
            $this->info('No answer engine is switched on (Admin → SEO and AEO). Nothing to do.');

            return self::SUCCESS;
        }
        $month = (string) ($this->option('month') ?: now()->format('Y-m'));
        $added = Visibility::refreshQuestions();
        $max = max(1, (int) ($settings->get('aeo.max_questions') ?? Visibility::DEFAULT_MAX));
        // Owner's own questions first, then the automatic ones.
        $questions = DB::table('aeo_questions')->where('active', true)->orderByRaw("CASE WHEN source = 'admin' THEN 0 ELSE 1 END")->orderBy('id')->limit($max)->pluck('id');
        $queued = 0;
        foreach ($questions as $id) {
            foreach ($on as $engine) {
                $done = DB::table('aeo_results')->where(['question_id' => $id, 'engine' => $engine, 'month' => $month])->whereNull('error')->exists();
                if (! $done || $this->option('force')) {
                    AskAnswerEngine::dispatch((int) $id, $engine, $month);
                    $queued++;
                }
            }
        }
        $this->info("{$added} new questions; {$queued} questions queued for ".implode(', ', $on)." ({$month}).");

        return self::SUCCESS;
    }
}
