<?php

declare(strict_types=1);

namespace App\Domains\Identity\Console;

use App\Domains\Identity\Actions\PasswordSetupToken;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\StaffInvitationNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Bootstraps the first super admin. They receive an email to set a password, then must turn on two-step sign-in. */
final class MakeSuperAdmin extends Command
{
    protected $signature = 't2t:super-admin {email} {--first=Platform} {--last=Admin}';

    protected $description = 'Create or promote a super admin and email them a link to set their password';

    public function handle(): int
    {
        $this->call(SyncPermissions::class);
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first() ?? User::create([
            'first_name' => (string) $this->option('first'),
            'last_name' => (string) $this->option('last'),
            'email' => $email,
            'password' => Str::password(40),
        ]);
        $user->syncRoles([Permissions::SUPER_ADMIN]);
        $token = PasswordSetupToken::for($user);
        $user->notify(new StaffInvitationNotification($token, invitedBy: 'Team to Team', superAdmin: true));
        activity('admin')->performedOn($user)->event('super-admin-granted')->log('Super admin role granted from the command line');
        $this->info("{$email} is a super admin. A set-password email has been sent.");

        return self::SUCCESS;
    }
}
