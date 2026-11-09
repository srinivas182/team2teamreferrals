<?php

declare(strict_types=1);

namespace App\Domains\Platform\Providers;

use App\Domains\Platform\Settings\Settings;
use Illuminate\Contracts\Config\Repository;

/** Applies admin-chosen providers to Laravel's config at boot, so switching providers needs no deploy. */
final class ApplyProviderSettings
{
    public function __construct(private readonly Settings $settings, private readonly Repository $config) {}

    public function __invoke(): void
    {
        $this->storage();
        $this->mail();
    }

    /** Private files (licenses, W-9s, agreements) live on the "private" disk: local in development, S3-compatible in production. */
    private function storage(): void
    {
        $s = $this->settings;
        if ($s->get('storage.driver') === 's3' && $s->has('storage.s3.bucket')) {
            $this->config->set('filesystems.disks.private', [
                'driver' => 's3',
                'key' => $s->get('storage.s3.key'),
                'secret' => $s->get('storage.s3.secret'),
                'region' => $s->get('storage.s3.region', 'us-east-1'),
                'bucket' => $s->get('storage.s3.bucket'),
                'endpoint' => $s->get('storage.s3.endpoint'),
                'use_path_style_endpoint' => $s->has('storage.s3.endpoint'),
                'visibility' => 'private',
                'throw' => true,
            ]);

            return;
        }
        $this->config->set('filesystems.disks.private', ['driver' => 'local', 'root' => storage_path('app/private'), 'visibility' => 'private', 'throw' => true]);
    }

    private function mail(): void
    {
        $s = $this->settings;
        if ($from = $s->get('mail.from_address')) {
            $this->config->set('mail.from.address', $from);
        }
        if ($name = $s->get('mail.from_name')) {
            $this->config->set('mail.from.name', $name);
        }

        $driver = $s->get('mail.driver');
        if ($driver === null || ! in_array($driver, ProviderCatalogue::drivers('mail'), true)) {
            return; // Keep the environment's mailer (log in development, array in tests).
        }

        match ($driver) {
            'smtp' => $this->config->set('mail.mailers.smtp', array_merge((array) $this->config->get('mail.mailers.smtp'), [
                'host' => $s->get('mail.smtp.host'),
                'port' => (int) $s->get('mail.smtp.port', '587'),
                'username' => $s->get('mail.smtp.username'),
                'password' => $s->get('mail.smtp.password'),
            ])),
            'ses' => $this->config->set('services.ses', ['key' => $s->get('mail.ses.key'), 'secret' => $s->get('mail.ses.secret'), 'region' => $s->get('mail.ses.region', 'us-east-1')]),
            'postmark' => $this->config->set('services.postmark.key', $s->get('mail.postmark.token')),
            'mailgun' => $this->config->set('services.mailgun', ['domain' => $s->get('mail.mailgun.domain'), 'secret' => $s->get('mail.mailgun.secret'), 'endpoint' => $s->get('mail.mailgun.endpoint', 'api.mailgun.net'), 'scheme' => 'https']),
            default => null,
        };
        $this->config->set('mail.default', $driver);
    }
}
