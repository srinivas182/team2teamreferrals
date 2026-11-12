<?php

declare(strict_types=1);

namespace App\Domains\Network\Training\Video;

use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HeyGen: renders a script with a stock presenter and voice, with captions. Presenters and voices come only from
 * HeyGen's stock lists (custom "talking photo" avatars, which could be anyone's likeness, are excluded).
 */
final class HeyGen
{
    public const BASE = 'https://api.heygen.com';

    public function __construct(private readonly Settings $settings) {}

    public function configured(): bool
    {
        return $this->settings->get('video.driver') === 'heygen' && $this->settings->has('video.heygen.api_key');
    }

    /** @return list<array{id: string, name: string, preview: ?string}> */
    public function avatars(): array
    {
        return Cache::remember('heygen.avatars', 3600, function () {
            $res = $this->http()->get(self::BASE.'/v2/avatars');
            $this->check($res, 'list presenters');

            return array_values(array_map(fn ($a) => ['id' => (string) $a['avatar_id'], 'name' => (string) ($a['avatar_name'] ?? $a['avatar_id']), 'preview' => $a['preview_image_url'] ?? null],
                (array) $res->json('data.avatars', [])));
        });
    }

    /** @return list<array{id: string, name: string, language: ?string}> */
    public function voices(): array
    {
        return Cache::remember('heygen.voices', 3600, function () {
            $res = $this->http()->get(self::BASE.'/v2/voices');
            $this->check($res, 'list voices');

            return array_values(array_map(fn ($v) => ['id' => (string) $v['voice_id'], 'name' => (string) ($v['name'] ?? $v['voice_id']), 'language' => $v['language'] ?? null],
                array_filter((array) $res->json('data.voices', []), fn ($v) => ($v['language'] ?? 'English') === 'English')));
        });
    }

    /** Start a render; returns the provider's video id. */
    public function render(string $avatarId, string $voiceId, string $script, string $callbackId): string
    {
        $res = $this->http()->post(self::BASE.'/v2/video/generate', [
            'video_inputs' => [['character' => ['type' => 'avatar', 'avatar_id' => $avatarId, 'avatar_style' => 'normal'], 'voice' => ['type' => 'text', 'input_text' => $script, 'voice_id' => $voiceId]]],
            'dimension' => ['width' => 1280, 'height' => 720], 'caption' => true, 'callback_id' => $callbackId,
        ]);
        $this->check($res, 'start the render');
        $id = $res->json('data.video_id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('HeyGen didn’t return a video id.');
        }

        return $id;
    }

    /** @return array{status: string, video_url: ?string, caption_url: ?string, error: ?string} status: processing, completed or failed */
    public function status(string $videoId): array
    {
        $res = $this->http()->get(self::BASE.'/v1/video_status.get', ['video_id' => $videoId]);
        $this->check($res, 'check the render');
        $s = (string) $res->json('data.status', 'processing');

        return ['status' => match ($s) {
            'completed' => 'completed', 'failed' => 'failed', default => 'processing'
        },
            'video_url' => $res->json('data.video_url'), 'caption_url' => $res->json('data.caption_url'), 'error' => is_string($res->json('data.error.message')) ? $res->json('data.error.message') : null];
    }

    public function pricePerMinute(): float
    {
        return (float) $this->settings->get('video.heygen.price_per_minute', '0');
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['X-Api-Key' => (string) $this->settings->get('video.heygen.api_key'), 'Accept' => 'application/json'])->timeout(30);
    }

    private function check(\Illuminate\Http\Client\Response $res, string $what): void
    {
        if ($res->failed()) {
            throw new RuntimeException("HeyGen couldn’t {$what}: ".($res->json('error.message') ?? $res->json('message') ?? $res->status()));
        }
    }
}
