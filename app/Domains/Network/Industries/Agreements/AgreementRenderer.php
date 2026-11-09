<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Agreements;

/** Fills merge fields, adds state addenda, and explains the key terms in plain English. */
final class AgreementRenderer
{
    /**
     * @param  list<array{key: string, title: string, text: string, enabled?: bool}>  $clauses
     * @param  array<string, string>  $data
     * @param  array<int, array{state: string, text: string}>  $addenda
     * @return list<array{title: string, text: string}>
     */
    public static function render(array $clauses, array $data, array $addenda = []): array
    {
        $out = [];
        foreach ($clauses as $c) {
            if (! ($c['enabled'] ?? true)) {
                continue;
            }
            $out[] = ['title' => $c['title'], 'text' => self::fill($c['text'], $data)];
        }
        foreach ($addenda as $a) {
            $out[] = ['title' => 'State requirements: '.$a['state'], 'text' => self::fill($a['text'], $data)];
        }

        return $out;
    }

    /** @param array<string, string> $data */
    public static function fill(string $text, array $data): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $data[$m[1]] ?? $m[0], $text);
    }

    /**
     * The plain-English summary both parties see before signing.
     *
     * @param  list<array{key: string, text?: string, enabled?: bool}>  $clauses
     * @param  array<string, string>  $data
     * @return list<string>
     */
    public static function summary(array $clauses, array $data): array
    {
        $has = fn (string $k) => collect($clauses)->contains(fn ($c) => $c['key'] === $k && ($c['enabled'] ?? true));
        $lines = ["{$data['sender_team']} is introducing {$data['client']} to {$data['receiver_team']} in {$data['city']}."];
        if ($has('fee')) {
            // If the fee wording was edited, quote it rather than paraphrase: the summary must never misstate the terms.
            $fee = collect($clauses)->firstWhere('key', 'fee');
            $default = collect(AgreementDefaults::for(\App\Domains\Network\Industries\Tier::Open))->firstWhere('key', 'fee');
            $lines[] = is_array($fee) && is_array($default) && isset($fee['text']) && $fee['text'] !== $default['text']
                ? 'Fee terms: '.self::fill((string) $fee['text'], $data)
                : "If {$data['client']} completes a transaction within {$data['protection_months']} months, {$data['receiver_team']} pays {$data['sender_team']} {$data['fee_terms']}, within {$data['fee_due_days']} days of closing.";
            $lines[] = 'The fee is paid business to business. Team to Team never collects or holds it.';
        }
        if ($has('no_fee')) {
            $lines[] = 'No money changes hands for this introduction.';
        }
        if ($has('cancellation')) {
            $lines[] = 'Either side can cancel in writing before the client signs.';
        }
        $lines[] = "If there’s a disagreement, the laws of {$data['governing_state']} apply".($has('mediation') ? ', after trying mediation first.' : '.');

        return $lines;
    }

    /**
     * Word-level comparison of two versions, clause by clause, for counsel review.
     *
     * @param  list<array{key: string, title: string, text: string, enabled: bool}>  $a
     * @param  list<array{key: string, title: string, text: string, enabled: bool}>  $b
     * @return list<array{key: string, title: string, status: string, parts: list<array{0: string, 1: string}>}>
     */
    public static function compare(array $a, array $b): array
    {
        $byKey = fn (array $list) => collect($list)->keyBy('key');
        $old = $byKey($a);
        $new = $byKey($b);
        $out = [];
        foreach ($new as $key => $c) {
            $o = $old->get($key);
            if ($o === null) {
                $out[] = ['key' => $key, 'title' => $c['title'], 'status' => 'added', 'parts' => [['+', $c['text']]]];
            } elseif ($o['text'] !== $c['text'] || ($o['enabled'] ?? true) !== ($c['enabled'] ?? true)) {
                $out[] = ['key' => $key, 'title' => $c['title'], 'status' => ($c['enabled'] ?? true) ? 'changed' : 'switched off', 'parts' => self::diff($o['text'], $c['text'])];
            }
        }
        foreach ($old as $key => $o) {
            if (! $new->has($key)) {
                $out[] = ['key' => $key, 'title' => $o['title'], 'status' => 'removed', 'parts' => [['-', $o['text']]]];
            }
        }

        return $out;
    }

    /** @return list<array{0: string, 1: string}> parts tagged "=", "+" or "-" */
    public static function diff(string $from, string $to): array
    {
        $x = preg_split('/(\s+)/', $from, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $y = preg_split('/(\s+)/', $to, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $n = count($x);
        $m = count($y);
        $l = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $l[$i][$j] = $x[$i] === $y[$j] ? $l[$i + 1][$j + 1] + 1 : max($l[$i + 1][$j], $l[$i][$j + 1]);
            }
        }
        $parts = [];
        $push = function (string $tag, string $s) use (&$parts): void {
            $last = count($parts) - 1;
            if ($last >= 0 && $parts[$last][0] === $tag) {
                $parts[$last][1] .= $s;
            } else {
                $parts[] = [$tag, $s];
            }
        };
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($x[$i] === $y[$j]) {
                $push('=', $x[$i]);
                $i++;
                $j++;
            } elseif ($l[$i + 1][$j] >= $l[$i][$j + 1]) {
                $push('-', $x[$i++]);
            } else {
                $push('+', $y[$j++]);
            }
        }
        while ($i < $n) {
            $push('-', $x[$i++]);
        }
        while ($j < $m) {
            $push('+', $y[$j++]);
        }

        return $parts;
    }
}
