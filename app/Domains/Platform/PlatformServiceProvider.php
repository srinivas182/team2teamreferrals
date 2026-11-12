<?php

declare(strict_types=1);

namespace App\Domains\Platform;

use App\Domains\Platform\Deliveries\MessageDelivery;
use App\Domains\Platform\Deliveries\RecordingSmsSender;
use App\Domains\Platform\Providers\ApplyProviderSettings;
use App\Domains\Platform\Settings\Settings;
use App\Support\Sms\LogSmsSender;
use App\Support\Sms\SmsSender;
use App\Support\Sms\TwilioSmsSender;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mime\Address;

/** Settings, providers and the delivery log: the plumbing every other domain sends through. */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: under Octane a singleton would keep one request's settings for the next.
        $this->app->scoped(Settings::class);
        $this->app->singleton(Search\SearchRegistry::class, function () {
            $r = new Search\SearchRegistry;
            $r->register(new Search\PagesSource);
            $r->register(new Search\PeopleSource);

            return $r;
        });

        // The SMS driver follows the admin setting; every driver is wrapped so sends are logged.
        $this->app->bind(SmsSender::class, function ($app): SmsSender {
            $s = $app->make(Settings::class);
            if ($s->get('sms.driver') === 'twilio' && $s->has('sms.twilio.sid') && $s->has('sms.twilio.token') && $s->has('sms.twilio.from')) {
                return new RecordingSmsSender(new TwilioSmsSender((string) $s->get('sms.twilio.sid'), (string) $s->get('sms.twilio.token'), (string) $s->get('sms.twilio.from')), 'twilio');
            }

            return new RecordingSmsSender(new LogSmsSender, 'log');
        });
        // The analytics store: MySQL by default; ClickHouse once its settings are filled in (Admin → Settings → Analytics).
        $this->app->bind(Analytics\AnalyticsStore::class, function ($app): Analytics\AnalyticsStore {
            $s = $app->make(Settings::class);
            if ($s->get('analytics.driver') === 'clickhouse' && $s->has('analytics.clickhouse.url')) {
                return new Analytics\ClickHouseStore((string) $s->get('analytics.clickhouse.url'), (string) $s->get('analytics.clickhouse.user', 'default'),
                    (string) $s->get('analytics.clickhouse.password', ''), (string) $s->get('analytics.clickhouse.database', 't2t'));
            }

            return new Analytics\MySqlStore;
        });
        $this->app->bind(\App\Support\Sms\WhatsAppSender::class, function ($app): \App\Support\Sms\WhatsAppSender {
            $s = $app->make(Settings::class);
            if ($s->get('sms.driver') === 'twilio' && $s->has('sms.twilio.sid') && $s->has('sms.twilio.token') && $s->has('sms.twilio.whatsapp_from')) {
                return new \App\Support\Sms\TwilioWhatsAppSender((string) $s->get('sms.twilio.sid'), (string) $s->get('sms.twilio.token'), (string) $s->get('sms.twilio.whatsapp_from'));
            }

            return new \App\Support\Sms\LogWhatsAppSender;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Analytics\AnalyticsCommand::class, \App\Domains\Referrals\Trust\TrustCommand::class]);
        }
        // Every minute: ship new records to the analytics store (never two runs at once).
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function (\Illuminate\Console\Scheduling\Schedule $s): void {
            $s->command('t2t:analytics ship')->everyMinute()->withoutOverlapping()->onOneServer();
            $s->command('t2t:trust')->dailyAt('02:30')->onOneServer(); // nightly trust scores
        });
        $this->app->make(ApplyProviderSettings::class)();
        Event::listen(\App\Domains\Seo\Events\PublicUrlsChanged::class, Cdn\PurgeCdn::class);
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        Event::listen(MessageSent::class, function (MessageSent $event): void {
            $to = array_map(fn (Address $a) => $a->getAddress(), $event->message->getTo());
            MessageDelivery::create([
                'channel' => 'mail',
                'provider' => (string) config('mail.default'),
                'recipient' => mb_substr(implode(', ', $to), 0, 255),
                'subject' => mb_substr((string) $event->message->getSubject(), 0, 255),
                'status' => 'sent',
                'provider_message_id' => $event->sent->getMessageId(),
            ]);
        });

        Event::listen(NotificationFailed::class, function (NotificationFailed $event): void {
            if ($event->channel !== 'mail') {
                return;
            }
            MessageDelivery::create([
                'channel' => 'mail',
                'provider' => (string) config('mail.default'),
                'recipient' => (string) data_get($event->notifiable, 'email', 'unknown'),
                'subject' => class_basename($event->notification),
                'status' => 'failed',
                'error' => mb_substr((string) json_encode($event->data), 0, 500),
            ]);
        });
    }
}
