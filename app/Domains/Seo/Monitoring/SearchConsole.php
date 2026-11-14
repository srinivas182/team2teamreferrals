<?php

declare(strict_types=1);

namespace App\Domains\Seo\Monitoring;

use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Google Search Console (Sprint 43b): submits the sitemap through Google's API whenever public pages change (at most
 * once an hour) and every night. Off until a super admin switches it on and adds a service account, in Admin → SEO
 * and AEO; Google still finds the sitemap through robots.txt meanwhile.
 */
final class SearchConsole
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters';

    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->get('seo.gsc.enabled') === '1' && $this->settings->has('seo.gsc.credentials') && trim((string) $this->settings->get('seo.gsc.site')) !== '';
    }

    /** After public pages change: submit within ten minutes, at most once an hour. */
    public function soon(): void
    {
        if ($this->enabled() && Cache::add('seo:gsc:debounce', 1, 3600)) {
            dispatch(fn () => app(self::class)->submit())->delay(now()->addMinutes(10));
        }
    }

    /** @return array{ok: bool, message: string, at: string} */
    public function submit(): array
    {
        try {
            if (! $this->enabled()) {
                throw new RuntimeException('Search Console is switched off or not set up.');
            }
            $site = trim((string) $this->settings->get('seo.gsc.site'));
            $sitemap = rtrim((string) config('seo.canonical_url'), '/').'/sitemap.xml';
            $r = Http::withToken($this->token())->timeout(20)
                ->put('https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($site).'/sitemaps/'.rawurlencode($sitemap));
            $result = $r->successful()
                ? ['ok' => true, 'message' => 'Sitemap submitted: '.$sitemap]
                : ['ok' => false, 'message' => 'Google answered '.$r->status().': '.mb_substr((string) ($r->json('error.message') ?? $r->body()), 0, 200)];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 250)];
        }
        $result['at'] = now()->toIso8601String();
        Cache::forever('seo:gsc:last', $result);

        return $result;
    }

    /** An access token for the service account (signed JWT exchanged at Google's token endpoint, cached ~55 minutes). */
    private function token(): string
    {
        $creds = json_decode((string) $this->settings->get('seo.gsc.credentials'), true);
        if (! is_array($creds) || ! isset($creds['client_email'], $creds['private_key'])) {
            throw new RuntimeException('The service account key is not a valid Google JSON key file.');
        }

        return Cache::remember('seo:gsc:token:'.sha1((string) $creds['client_email']), 3300, function () use ($creds): string {
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $uri = (string) ($creds['token_uri'] ?? 'https://oauth2.googleapis.com/token');
            $head = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $b64((string) json_encode(['iss' => $creds['client_email'], 'scope' => self::SCOPE, 'aud' => $uri, 'iat' => time(), 'exp' => time() + 3600]));
            $key = openssl_pkey_get_private((string) $creds['private_key']);
            if ($key === false || ! openssl_sign($head.'.'.$claims, $sig, $key, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('The service account private key could not be read.');
            }
            $r = Http::asForm()->timeout(20)->post($uri, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $head.'.'.$claims.'.'.$b64($sig)]);
            if (! $r->successful() || ! is_string($r->json('access_token'))) {
                throw new RuntimeException('Google refused the service account: '.mb_substr((string) ($r->json('error_description') ?? $r->body()), 0, 200));
            }

            return (string) $r->json('access_token');
        });
    }
}
