<?php

declare(strict_types=1);

namespace App\Domains\Platform\Ai;

use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to the AI providers chosen in Admin → Settings: the main provider, then the fallback when the main one fails
 * or reaches its monthly spend cap. Supports Anthropic (Claude) and OpenAI. Requests go through each provider's API,
 * which doesn't train on the data sent. Every call is logged with its provider, model, tokens and cost.
 */
final class AiProvider
{
    public const DRIVERS = ['anthropic', 'openai'];

    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return $this->chain() !== [];
    }

    public function spentThisMonth(?string $provider = null): float
    {
        return (float) DB::table('ai_usage')->where('month', now()->format('Y-m'))->when($provider, fn ($q, $p) => $q->where('provider', $p))->sum('cost_usd');
    }

    /**
     * Speech to text, through OpenAI (whichever AI channel has it set up). Anthropic's API doesn't transcribe audio.
     */
    public function transcribe(string $audio, string $filename, string $purpose): string
    {
        $prefix = null;
        foreach (['ai', 'ai_fallback'] as $channel) {
            if ($this->settings->get("{$channel}.driver") === 'openai' && $this->settings->has("{$channel}.openai.api_key")) {
                $prefix = "{$channel}.openai";
                break;
            }
        }
        if ($prefix === null) {
            throw new RuntimeException('Voice notes need OpenAI set up as an AI provider.');
        }
        $res = Http::withToken((string) $this->settings->get("{$prefix}.api_key"))->timeout(60)->attach('file', $audio, $filename)
            ->post('https://api.openai.com/v1/audio/transcriptions', ['model' => (string) $this->settings->get("{$prefix}.transcribe_model", 'gpt-4o-mini-transcribe')]);
        if ($res->failed()) {
            throw new RuntimeException('Transcription failed: '.($res->json('error.message') ?? $res->status()));
        }
        DB::table('ai_usage')->insert(['month' => now()->format('Y-m'), 'provider' => 'openai', 'model' => (string) $this->settings->get("{$prefix}.transcribe_model", 'gpt-4o-mini-transcribe'), 'purpose' => $purpose,
            'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return trim((string) $res->json('text', ''));
    }

    /**
     * @param  array{data: string, mime: string}|null  $image  base64 image the model should read
     * @return array{text: string, input_tokens: int, output_tokens: int, cost_usd: float, model: string, provider: string}
     */
    public function complete(string $prompt, string $purpose, int $maxTokens = 300, ?string $system = null, ?array $image = null): array
    {
        $chain = $this->chain();
        if ($chain === []) {
            throw new RuntimeException('AI is not connected.');
        }
        $last = null;
        foreach ($chain as [$driver, $prefix]) {
            $cap = (float) $this->settings->get("{$prefix}.monthly_cap", '0');
            if ($cap > 0 && $this->spentThisMonth($driver) >= $cap) {
                $last = new RuntimeException("The {$driver} spend cap for this month has been reached.");

                continue; // try the fallback
            }
            try {
                return $this->call($driver, $prefix, $prompt, $purpose, $maxTokens, $system, $image);
            } catch (RuntimeException $e) {
                $last = $e; // try the fallback
            }
        }
        throw $last;
    }

    /** @return list<array{0: string, 1: string}> [driver, settings prefix], main provider first */
    private function chain(): array
    {
        $out = [];
        foreach (['ai', 'ai_fallback'] as $channel) {
            $driver = (string) $this->settings->get("{$channel}.driver", 'none');
            if (in_array($driver, self::DRIVERS, true) && $this->settings->has("{$channel}.{$driver}.api_key")) {
                $out[] = [$driver, "{$channel}.{$driver}"];
            }
        }

        return $out;
    }

    /**
     * @param  array{data: string, mime: string}|null  $image
     * @return array{text: string, input_tokens: int, output_tokens: int, cost_usd: float, model: string, provider: string}
     */
    private function call(string $driver, string $prefix, string $prompt, string $purpose, int $maxTokens, ?string $system, ?array $image = null): array
    {
        $model = (string) $this->settings->get("{$prefix}.model");
        $key = (string) $this->settings->get("{$prefix}.api_key");
        if ($driver === 'anthropic') {
            $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(30)
                ->post('https://api.anthropic.com/v1/messages', array_filter(['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $image === null ? $prompt
                    : [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['data']]], ['type' => 'text', 'text' => $prompt]]]]]));
            $text = collect((array) $res->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");
            [$in, $out] = [(int) $res->json('usage.input_tokens', 0), (int) $res->json('usage.output_tokens', 0)];
        } else {
            $user = $image === null ? $prompt : [['type' => 'text', 'text' => $prompt], ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$image['mime'].';base64,'.$image['data']]]];
            $messages = array_values(array_filter([$system !== null ? ['role' => 'system', 'content' => $system] : null, ['role' => 'user', 'content' => $user]]));
            $res = Http::withToken($key)->timeout(30)->post('https://api.openai.com/v1/chat/completions', ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => $messages]);
            $text = (string) $res->json('choices.0.message.content', '');
            [$in, $out] = [(int) $res->json('usage.prompt_tokens', 0), (int) $res->json('usage.completion_tokens', 0)];
        }
        if ($res->failed()) {
            throw new RuntimeException(ucfirst($driver).' refused the request: '.($res->json('error.message') ?? $res->status()));
        }
        $cost = round(($in * (float) $this->settings->get("{$prefix}.input_price", '0') + $out * (float) $this->settings->get("{$prefix}.output_price", '0')) / 1_000_000, 4);
        DB::table('ai_usage')->insert(['month' => now()->format('Y-m'), 'provider' => $driver, 'model' => $model, 'purpose' => $purpose, 'input_tokens' => $in, 'output_tokens' => $out, 'cost_usd' => $cost, 'created_at' => now(), 'updated_at' => now()]);

        return ['text' => $text, 'input_tokens' => $in, 'output_tokens' => $out, 'cost_usd' => $cost, 'model' => $model, 'provider' => $driver];
    }
}
