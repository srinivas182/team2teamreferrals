<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Industries\Templates\Schemas;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Group owners shape their network's pipeline. Safe for work in progress: a stage that still holds referrals can
 * only be removed if the owner chooses where those referrals go; every change is a new version, recorded and announced.
 */
final class PipelineEditor
{
    public function __construct(private readonly IndustryTemplates $templates, private readonly Notifier $notifier) {}

    public static function locked(Group $group): bool
    {
        return (bool) data_get($group->rules, 'pipeline_locked', false);
    }

    /** @return array<string, int> open referrals per stage key */
    public static function counts(Group $group): array
    {
        /** @var array<string, int> */
        return Referral::query()->where(['group_id' => $group->id, 'status' => 'accepted'])->whereNull('closed_at')->selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     * @param  array<string, string>  $moves  removed stage key => stage key its referrals move to
     */
    public function save(Group $group, User $user, array $stages, array $moves, bool $asAdmin = false): void
    {
        if (self::locked($group) && ! $asAdmin) {
            throw ValidationException::withMessages(['stages' => 'The platform has locked this network’s pipeline. Contact support to change it.']);
        }
        $stages = array_map(fn ($s) => array_filter([
            'key' => (string) ($s['key'] ?? ''), 'label' => trim((string) ($s['label'] ?? '')), 'kind' => (string) ($s['kind'] ?? 'open'),
            'client_label' => trim((string) ($s['client_label'] ?? '')) ?: null, 'limit_days' => isset($s['limit_days']) && $s['limit_days'] !== '' ? (int) $s['limit_days'] : null,
        ], fn ($v) => $v !== null), $stages);
        $check = Schemas::checkStages($stages);
        if ($check['errors'] !== []) {
            throw ValidationException::withMessages(['stages' => $check['errors']]);
        }
        $newKeys = array_column($stages, 'key');
        $counts = self::counts($group);
        $missing = [];
        foreach ($counts as $key => $n) {
            if (! in_array($key, $newKeys, true) && ! in_array($moves[$key] ?? null, $newKeys, true)) {
                $missing[] = "{$n} referral(s) are in a stage you removed ({$key}); choose where they go.";
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages(['moves' => $missing]);
        }
        DB::transaction(function () use ($group, $user, $stages, $moves, $counts, $newKeys) {
            $this->templates->save('stages', $group->industry, $stages, $user->id, $group->id);
            foreach ($counts as $key => $n) {
                if (in_array($key, $newKeys, true)) {
                    continue;
                }
                $to = $moves[$key];
                foreach (Referral::query()->where(['group_id' => $group->id, 'status' => 'accepted', 'stage' => $key])->whereNull('closed_at')->get() as $ref) {
                    $ref->forceFill(['stage' => $to, 'stage_changed_at' => now()])->save();
                    DB::table('referral_events')->insert(['referral_id' => $ref->id, 'event' => 'stage', 'team_id' => null, 'actor_id' => $user->id, 'meta' => json_encode(['from' => $key, 'to' => $to, 'note' => 'The network’s pipeline changed']), 'created_at' => now()]);
                }
            }
        });
        activity('group')->causedBy($user)->performedOn($group)->event('pipeline-saved')->withProperties(['stages' => $newKeys, 'moves' => $moves])->log("Updated the {$group->name} pipeline");
        $leaders = User::query()->whereIn('id', DB::table('seats')->join('teams', 'teams.id', '=', 'seats.primary_team_id')->where('seats.group_id', $group->id)->pluck('teams.leader_id'))->get();
        foreach ($leaders as $l) {
            if ($l->id !== $user->id) {
                $this->notifier->send($l, 'network.announcement', ['body' => "{$group->name} updated its pipeline stages: ".implode(' → ', array_column($stages, 'label')).'. Your referrals are where you left them.'], url('/app/pipeline'), $group->industry, $group->id);
            }
        }
    }

    /** @return list<array<string, mixed>> the industry's stages (what "Reset" goes back to) */
    public function industryStages(Group $group): array
    {
        return $this->templates->resolve('stages', $group->industry)['body'];
    }
}
