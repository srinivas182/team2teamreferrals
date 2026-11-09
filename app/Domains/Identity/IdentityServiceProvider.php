<?php

declare(strict_types=1);

namespace App\Domains\Identity;

use App\Domains\Identity\Actions\CreateNewUser;
use App\Domains\Identity\Actions\RedirectIfTwoFactorRequired;
use App\Domains\Identity\Actions\ResetUserPassword;
use App\Domains\Identity\Actions\UpdateUserPassword;
use App\Domains\Identity\Audit\AuditSubscriber;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Console\MakeSuperAdmin;
use App\Domains\Identity\Console\SyncPermissions;
use App\Domains\Identity\Devices\DeviceRecorder;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\NewDeviceSignInNotification;
use App\Domains\Seo\SeoData;
use App\Support\Sms\LogSmsSender;
use App\Support\Sms\SmsSender;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;
use Spatie\Activitylog\Models\Activity;

/** Accounts and sign-in: Fortify (password, 2FA, passkeys, reset, verification) rendered as React pages. */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(SmsSender::class, LogSmsSender::class);
    }

    public function boot(): void
    {
        Passkeys::useUserModel(User::class);

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorRequired::class);

        $page = fn (string $component, string $title, array $props = []) => fn (Request $request) => Inertia::render($component, [
            ...$props,
            'status' => session('status'),
            'seo' => SeoData::private($title)->toArray(),
        ]);
        Fortify::loginView($page('auth/Login', 'Log in'));
        Fortify::registerView($page('auth/Register', 'Claim your city'));
        Fortify::requestPasswordResetLinkView($page('auth/ForgotPassword', 'Reset your password'));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => (string) $request->query('email'),
            'token' => (string) $request->route('token'),
            'seo' => SeoData::private('Choose a new password')->toArray(),
        ]));
        Fortify::verifyEmailView($page('auth/VerifyEmail', 'Verify your email'));
        Fortify::twoFactorChallengeView($page('auth/TwoFactorChallenge', 'Two-step sign-in'));
        Fortify::confirmPasswordView($page('auth/ConfirmPassword', 'Confirm your password'));

        // Super admins can do everything; staff only what they've been granted.
        Gate::before(fn ($user) => $user instanceof User && $user->hasRole(Permissions::SUPER_ADMIN) ? true : null);

        // Every audit entry records where it came from.
        Activity::creating(function (Activity $activity): void {
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                $activity->properties = collect($activity->properties)->merge(['ip' => null, 'device' => 'Command line']);

                return;
            }
            $request = request();
            $activity->properties = collect($activity->properties)->merge(['ip' => $request->ip(), 'device' => DeviceRecorder::label((string) $request->userAgent())]);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([SyncPermissions::class, MakeSuperAdmin::class]);
        }

        $this->rateLimits();
        $this->events();
        Event::subscribe(AuditSubscriber::class);
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }

    private function rateLimits(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip())));
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by((string) $request->session()->get('login.id')));
        RateLimiter::for('passkeys', fn (Request $request) => Limit::perMinute(10)->by(($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip()));
    }

    private function events(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }
            $request = request();
            $devices = app(DeviceRecorder::class);
            $recorded = $devices->record($event->user, $request);
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            if ($request->routeIs('two-factor.login.store') && $request->boolean('remember_device')) {
                $devices->trustForTwoFactor($recorded['device']);
            }
            // A brand-new device for an account that has signed in before: tell the owner, with a way to lock everyone out.
            if ($recorded['isNew'] && $event->user->devices()->count() > 1) {
                $event->user->notify(new NewDeviceSignInNotification(
                    device: $recorded['device']->label,
                    ip: $recorded['device']->ip_address,
                    when: now()->setTimezone($event->user->timezone)->format('M j, Y \a\t g:i A T'),
                    secureUrl: URL::temporarySignedRoute('account.secure', now()->addDays(7), ['user' => $event->user->ulid]),
                ));
            }
        });

        // Invited staff prove their email by setting a password from the invitation link.
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if ($event->user instanceof User && ! $event->user->hasVerifiedEmail()) {
                $event->user->markEmailAsVerified();
            }
        });

        // New members confirm their mobile right after sign-up, so the first code is sent automatically.
        Event::listen(Registered::class, function (Registered $event): void {
            if ($event->user instanceof User) {
                $event->user->assignRole(Permissions::MEMBER);
                app(Phone\PhoneVerifier::class)->send($event->user);
            }
        });
    }
}
