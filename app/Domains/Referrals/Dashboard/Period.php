<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Dashboard;

use Carbon\CarbonImmutable;

/** A dashboard's date range, from a preset or custom dates, and the previous period of the same length. */
final class Period
{
    public const PRESETS = ['last_7' => 'Last 7 days', 'last_30' => 'Last 30 days', 'last_90' => 'Last 90 days', 'this_month' => 'This month', 'last_month' => 'Last month', 'ytd' => 'Year to date', 'custom' => 'Custom'];

    public function __construct(public readonly CarbonImmutable $from, public readonly CarbonImmutable $to, public readonly string $preset) {}

    public static function from(?string $preset, ?string $from = null, ?string $to = null): self
    {
        $now = CarbonImmutable::now();
        $preset = isset(self::PRESETS[(string) $preset]) ? (string) $preset : 'last_30';

        return match ($preset) {
            'last_7' => new self($now->subDays(6)->startOfDay(), $now->endOfDay(), $preset),
            'last_90' => new self($now->subDays(89)->startOfDay(), $now->endOfDay(), $preset),
            'this_month' => new self($now->startOfMonth(), $now->endOfDay(), $preset),
            'last_month' => new self($now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth(), $preset),
            'ytd' => new self($now->startOfYear(), $now->endOfDay(), $preset),
            'custom' => ($from && $to && strtotime($from) && strtotime($to) && strtotime($from) <= strtotime($to))
                ? new self(CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->endOfDay(), $preset) : self::from('last_30'),
            default => new self($now->subDays(29)->startOfDay(), $now->endOfDay(), 'last_30'),
        };
    }

    /** The same number of days immediately before. */
    public function previous(): self
    {
        $days = (int) $this->from->diffInDays($this->to) + 1;

        return new self($this->from->subDays($days), $this->from->subSecond(), $this->preset);
    }

    /** @return array{preset: string, from: string, to: string, label: string} */
    public function toArray(): array
    {
        return ['preset' => $this->preset, 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'label' => self::PRESETS[$this->preset]];
    }
}
