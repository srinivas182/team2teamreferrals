<?php

declare(strict_types=1);

namespace App\Domains\Platform\Providers;

use App\Domains\Platform\Settings\Settings;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Applies admin-chosen providers to Laravel's config, so switching providers needs no deploy.
 *
 * Runs at boot, and again before every request under Octane and before every queued job ({@see self::refresh()}):
 * those processes boot once and live for hours, so a provider saved in Admin would otherwise only take effect after
 * a restart (found on the cPanel server: Brevo saved, test email still went to the log).
 */
final class ApplyProviderSettings
{
    public function __construct(private readonly Settings $settings, private readonly Repository $config) {}

    /**
     * Apply the saved providers to this application, and drop mailers and disks built with settings that changed
     * (only those: a disk or mailer whose settings are the same, or a test's fake, is kept).
     */
    public static function refresh(Application $app): void
    {
        $config = $app->make('config');
        $before = [$config->get('mail'), $config->get('services'), $config->get('filesystems.disks.private')];
        $app->make(self::class)();
        if ($app->resolved('mail.manager') && [$config->get('mail'), $config->get('services')] !== [$before[0], $before[1]]) {
            $app->make('mail.manager')->forgetMailers();
        }
        if ($app->resolved('filesystem') && $config->get('filesystems.disks.private') !== $before[2]) {
            $app->make('filesystem')->forgetDisk('private');
        }
    }

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
            // Brevo's SMTP relay (Admin → System → Email); the sender address must be verified in Brevo.
            'brevo' => $this->config->set('mail.mailers.brevo', ['transport' => 'smtp', 'scheme' => 'smtp', 'host' => 'smtp-relay.brevo.com', 'port' => 587,
                'username' => $s->get('mail.brevo.login'), 'password' => $s->get('mail.brevo.smtp_key'), 'timeout' => 15]),
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
