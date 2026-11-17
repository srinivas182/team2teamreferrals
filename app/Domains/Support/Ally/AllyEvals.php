<?php

declare(strict_types=1);

namespace App\Domains\Support\Ally;

use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Test questions for Ally: what a good answer must mention, what it must never say, and whether Ally should answer
 * or say it doesn't know. Run against the draft before publishing changes, and any time from Admin → Ally.
 */
final class AllyEvals
{
    /** Share of test questions that must pass before a draft can be published. */
    public const PASS_RATE = 80;

    /**
     * @param  array{tone: string, blocked: string}  $config
     * @return array{passed: int, total: int, rate: int}
     */
    public static function run(array $config, bool $draft = false): array
    {
        $passed = 0;
        $rows = DB::table('ally_evals')->orderBy('id')->get();
        foreach ($rows as $e) {
            $r = Ally::ask(null, AllyContext::for(null, null), (string) $e->question, $config);
            $text = mb_strtolower($r['answer'].' '.implode(' ', array_column($r['sources'], 'title')));
            $ok = ((bool) $e->expect_answer === $r['answered'])
                && collect((array) json_decode((string) $e->must_include, true))->every(fn ($w) => str_contains($text, mb_strtolower((string) $w)))
                && collect((array) json_decode((string) ($e->must_not_include ?? '[]'), true))->every(fn ($w) => ! str_contains($text, mb_strtolower((string) $w)));
            $passed += $ok ? 1 : 0;
            DB::table('ally_evals')->where('id', $e->id)->update(['last_run_at' => now(), 'last_passed' => $ok, 'last_answer' => mb_substr($r['answer'], 0, 2000), 'updated_at' => now()]);
        }
        $total = $rows->count();
        $rate = $total > 0 ? (int) floor($passed * 100 / $total) : 100;
        if ($draft) {
            $s = app(Settings::class);
            $s->set('ally.draft.tested', hash('sha256', json_encode($config).'|'.self::fingerprint()));
            $s->set('ally.draft.rate', (string) $rate);
        }

        return ['passed' => $passed, 'total' => $total, 'rate' => $rate];
    }

    /** The draft was tested as it is now (same settings, same test questions) and passed enough. */
    public static function draftReady(): bool
    {
        $s = app(Settings::class);

        return $s->get('ally.draft.tested') === hash('sha256', json_encode(AllySettings::draft()).'|'.self::fingerprint())
            && (int) $s->get('ally.draft.rate', '0') >= self::PASS_RATE;
    }

    private static function fingerprint(): string
    {
        // The questions and what they expect (not their last results), so running the tests doesn't change it.
        return hash('sha256', (string) json_encode(DB::table('ally_evals')->orderBy('id')->get(['id', 'question', 'audience', 'must_include', 'must_not_include', 'expect_answer'])));
    }
}
