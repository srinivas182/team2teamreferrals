<?php

declare(strict_types=1);

namespace App\Domains\Platform\Ai;

use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One way to call AI models. Anthropic first; spend is metered per month against the admin's cap.
 * Requests go through the API, whose data is not used for model training by default.
 */
final class AiProvider
{
    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->get('ai.driver') === 'anthropic' && $this->settings->has('ai.anthropic.api_key');
    }

    public function spentThisMonth(): float
    {
        return (float) DB::table('ai_usage')->where('month', now()->format('Y-m'))->sum('cost_usd');
    }

    /** @return array{text: string, input_tokens: int, output_tokens: int, cost_usd: float, model: string} */
    public function complete(string $prompt, string $purpose, int $maxTokens = 300): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('AI is not connected.');
        }
        $cap = (float) $this->settings->get('ai.anthropic.monthly_cap', '0');
        if ($cap > 0 && $this->spentThisMonth() >= $cap) {
            throw new RuntimeException('This month’s AI spend cap has been reached.');
        }
        $model = (string) $this->settings->get('ai.anthropic.model');
        $res = Http::withHeaders(['x-api-key' => (string) $this->settings->get('ai.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])
            ->timeout(30)->post('https://api.anthropic.com/v1/messages', ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => [['role' => 'user', 'content' => $prompt]]]);
        if ($res->failed()) {
            throw new RuntimeException('Anthropic refused the request: '.($res->json('error.message') ?? $res->status()));
        }
        $in = (int) $res->json('usage.input_tokens', 0);
        $out = (int) $res->json('usage.output_tokens', 0);
        $cost = round(($in * (float) $this->settings->get('ai.anthropic.input_price', '0') + $out * (float) $this->settings->get('ai.anthropic.output_price', '0')) / 1_000_000, 4);
        DB::table('ai_usage')->insert(['month' => now()->format('Y-m'), 'model' => $model, 'purpose' => $purpose, 'input_tokens' => $in, 'output_tokens' => $out, 'cost_usd' => $cost, 'created_at' => now(), 'updated_at' => now()]);
        $text = collect((array) $res->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");

        return ['text' => $text, 'input_tokens' => $in, 'output_tokens' => $out, 'cost_usd' => $cost, 'model' => $model];
    }
}
