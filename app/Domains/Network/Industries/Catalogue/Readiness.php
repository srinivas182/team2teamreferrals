<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Catalogue;

use App\Domains\Network\Industries\Agreements\Agreements;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Locations\Models\State;
use Illuminate\Support\Facades\DB;

/** What is left before each industry can go live: one place to track launch prep across all 46. */
final class Readiness
{
    public function __construct(private readonly Agreements $agreements) {}

    /**
     * @return list<array{slug: string, name: string, tier: string, family: string, status: string, checks: array<string, array{ok: bool|null, detail: string}>, ready: bool}>
     */
    public function board(): array
    {
        $states = State::query()->where('is_enabled', true)->count();
        $licensing = DB::table('industry_licensing')->selectRaw('industry_id, count(*) as n')->groupBy('industry_id')->pluck('n', 'industry_id');
        $out = [];
        foreach (Industry::query()->orderBy('name')->get() as $i) {
            $agreement = $i->tier === Tier::Directory ? null : $this->agreements->forSigning($i);
            $done = (int) ($licensing[$i->id] ?? 0);
            $checks = [
                'terms' => ['ok' => true, 'detail' => "{$i->terms()['pro']} · {$i->terms()['client']} · {$i->terms()['referral']}"],
                'agreement' => $i->tier === Tier::Directory ? ['ok' => null, 'detail' => 'Not needed (directory)'] : ($agreement ? ['ok' => true, 'detail' => "v{$agreement['version']} approved"] : ['ok' => false, 'detail' => 'Not approved yet']),
                'licensing' => ! $i->license_required ? ['ok' => null, 'detail' => 'No license required'] : ['ok' => $states > 0 && $done >= $states, 'detail' => "{$done} of {$states} states"],
                'compliance' => $i->isApproved() ? ['ok' => true, 'detail' => 'Signed off'] : ['ok' => false, 'detail' => 'Needs sign-off'],
                'group' => ['ok' => null, 'detail' => 'Created in Sprint 9b'],
            ];
            $ready = collect($checks)->every(fn ($c) => $c['ok'] !== false);
            $out[] = ['slug' => $i->slug, 'name' => $i->name, 'tier' => $i->tier->value, 'family' => $i->family, 'status' => (string) $i->getAttribute('status'), 'checks' => $checks, 'ready' => $ready];
        }

        return $out;
    }

    /** @return array{ready: int, total: int} */
    public function summary(): array
    {
        $b = $this->board();

        return ['ready' => count(array_filter($b, fn ($r) => $r['ready'])), 'total' => count($b)];
    }
}
