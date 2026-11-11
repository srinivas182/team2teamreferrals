<?php

declare(strict_types=1);

namespace App\Domains\Platform\Ai;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one way features use AI. Every call is metered against the team that asked; credits and the daily cap are
 * checked first; the active prompt version is used; variables are inserted as data. When AI is off, out of
 * credit or failing, it returns null and the feature shows its plain version: AI never blocks work.
 */
final class AiGateway
{
    public function __construct(private readonly AiProvider $provider) {}

    /**
     * @param  array<string, string>  $vars
     * @return array{text: string, usage_id: int}|null
     */
    public function run(Team $team, ?User $user, string $feature, string $promptKey, array $vars, int $credits = 1, int $maxTokens = 300): ?array
    {
        $log = fn (string $status, ?string $reason = null, array $extra = []) => DB::table('ai_usage_logs')->insertGetId([
            'team_id' => $team->id, 'user_id' => $user?->id, 'feature' => $feature, 'prompt_key' => $promptKey, 'status' => $status, 'reason' => $reason, 'created_at' => now(), ...$extra]);
        if (! $this->provider->enabled()) {
            $log('blocked', 'AI is off');

            return null;
        }
        AiCredits::grantMonthly($team);
        if (AiCredits::balance($team) < $credits) {
            $log('blocked', 'No credits left');

            return null;
        }
        if (AiCredits::usedToday($team) + $credits > AiCredits::setting('daily_cap')) {
            $log('blocked', 'Daily cap reached');

            return null;
        }
        $p = Prompts::active($promptKey);
        try {
            $r = $this->provider->complete(Prompts::fill($p['template'], $vars), $feature, $maxTokens, Prompts::fill($p['system'], $vars));
        } catch (Throwable $e) {
            report($e);
            $log('failed', mb_substr($e->getMessage(), 0, 120), ['prompt_version' => $p['version']]);

            return null;
        }
        $id = (int) $log('ok', null, ['prompt_version' => $p['version'], 'provider' => $r['provider'], 'model' => $r['model'], 'input_tokens' => $r['input_tokens'],
            'output_tokens' => $r['output_tokens'], 'cost_usd' => $r['cost_usd'], 'credits' => $credits]);
        AiCredits::charge($team, $credits, $id);

        return ['text' => trim($r['text']), 'usage_id' => $id];
    }
}
