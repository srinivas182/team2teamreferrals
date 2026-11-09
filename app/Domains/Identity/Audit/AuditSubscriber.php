<?php

declare(strict_types=1);

namespace App\Domains\Identity\Audit;

use App\Domains\Identity\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * Writes sign-in and security events to the audit log ("auth" and "security" logs).
 * IP address and device are added to every entry by the Activity model hook in IdentityServiceProvider.
 */
final class AuditSubscriber
{
    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Logout::class => 'logout',
            Failed::class => 'failed',
            Lockout::class => 'lockout',
            PasswordReset::class => 'passwordReset',
            Verified::class => 'emailVerified',
            TwoFactorAuthenticationConfirmed::class => 'twoFactorOn',
            TwoFactorAuthenticationDisabled::class => 'twoFactorOff',
            TwoFactorAuthenticationFailed::class => 'twoFactorFailed',
            RecoveryCodesGenerated::class => 'recoveryCodes',
            PasskeyRegistered::class => 'passkeyAdded',
            PasskeyDeleted::class => 'passkeyRemoved',
        ];
    }

    public function login(Login $e): void
    {
        $this->log('auth', 'signed-in', 'Signed in', $e->user);
    }

    public function logout(Logout $e): void
    {
        $this->log('auth', 'signed-out', 'Signed out', $e->user);
    }

    public function failed(Failed $e): void
    {
        $email = (string) ($e->credentials['email'] ?? '');
        activity('auth')->event('sign-in-failed')->withProperties(['email' => $email])->log('Failed sign-in attempt');
    }

    public function lockout(Lockout $e): void
    {
        activity('auth')->event('locked-out')->withProperties(['email' => (string) $e->request->input('email')])->log('Too many sign-in attempts; temporarily locked out');
    }

    public function passwordReset(PasswordReset $e): void
    {
        $this->log('security', 'password-reset', 'Password reset by email link', $e->user);
    }

    public function emailVerified(Verified $e): void
    {
        $this->log('security', 'email-verified', 'Email address verified', $e->user);
    }

    public function twoFactorOn(TwoFactorAuthenticationConfirmed $e): void
    {
        $this->log('security', 'two-factor-enabled', 'Two-step sign-in turned on', $e->user);
    }

    public function twoFactorOff(TwoFactorAuthenticationDisabled $e): void
    {
        $this->log('security', 'two-factor-disabled', 'Two-step sign-in turned off', $e->user);
    }

    public function twoFactorFailed(TwoFactorAuthenticationFailed $e): void
    {
        $this->log('auth', 'two-factor-failed', 'Wrong two-step sign-in code', $e->user);
    }

    public function recoveryCodes(RecoveryCodesGenerated $e): void
    {
        $this->log('security', 'recovery-codes-generated', 'New recovery codes generated', $e->user);
    }

    public function passkeyAdded(PasskeyRegistered $e): void
    {
        $this->log('security', 'passkey-added', 'Passkey added: '.$e->passkey->name, $e->user);
    }

    public function passkeyRemoved(PasskeyDeleted $e): void
    {
        $this->log('security', 'passkey-removed', 'Passkey removed: '.$e->passkey->name, $e->user);
    }

    private function log(string $logName, string $event, string $description, mixed $user): void
    {
        $logger = activity($logName)->event($event);
        if ($user instanceof User) {
            $logger->causedBy($user)->performedOn($user);
        }
        $logger->log($description);
    }
}
