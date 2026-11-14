<?php

declare(strict_types=1);

namespace App\Domains\Seo\Monitoring;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/** One question to one engine for the monthly visibility check (queued, so a slow engine doesn't hold up the rest). */
final class AskAnswerEngine implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 150;

    public function __construct(public readonly int $questionId, public readonly string $engine, public readonly string $month) {}

    public function handle(AnswerEngines $engines): void
    {
        $q = DB::table('aeo_questions')->where('id', $this->questionId)->value('question');
        if ($q === null) {
            return;
        }
        try {
            Visibility::record($this->questionId, $this->engine, $this->month, $engines->ask($this->engine, (string) $q));
        } catch (Throwable $e) {
            Visibility::record($this->questionId, $this->engine, $this->month, null, $e->getMessage());
        }
    }
}
