<?php

/*
| CI only: people and records for the visual checks of signed-in screens (after seed-public-pages.php).
| Run: php artisan tinker --execute="require base_path('scripts/ci/seed-screens.php');"
| Accounts: member@example.com (team leader) and admin@example.com (super admin, two-step sign-in with the
| authenticator secret JBSWY3DPEHPK3PXP), both with the password "visual-checks-2027".
*/

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Partners\Partners;
use App\Domains\Support\Tickets;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

abort_if(app()->environment('production') && ! env('CI'), 403, 'CI only');
$password = bcrypt('visual-checks-2027');

// A member who leads a seated team (Lone Star Solar, from the public pages seed).
$team = Team::query()->where('name', 'Lone Star Solar')->firstOrFail();
$member = $team->leader;
$member->forceFill(['email' => 'member@example.com', 'first_name' => 'Mia', 'last_name' => 'Member', 'password' => $password, 'email_verified_at' => now(),
    'phone' => '+14355550150', 'phone_verified_at' => now(), 'tours_done' => json_encode(['member-home'])])->save();

// A super admin with two-step sign-in (the checks compute the code from the secret).
$admin = User::factory()->create(['email' => 'admin@example.com', 'first_name' => 'Ada', 'last_name' => 'Admin', 'password' => $password, 'phone' => '+14355550151', 'phone_verified_at' => now()]);
$admin->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => encrypt(json_encode(['visual-aaaa', 'visual-bbbb'])), 'two_factor_confirmed_at' => now(),
    'tours_done' => json_encode(['member-home', 'admin-home'])])->save();
$admin->assignRole(Permissions::SUPER_ADMIN);

// An approved partner (in its free trial) and a waiting application.
$apply = fn (string $name, string $email) => Partners::apply(['category' => 'home-stagers', 'business_name' => $name, 'contact_name' => 'Pat Partner', 'email' => $email,
    'description' => 'We stage homes for sale across Austin so they photograph well and sell faster.', 'cities' => [DB::table('cities')->where('slug', 'austin')->value('id')]], null, null);
Partners::approve($apply('Stage Right Austin', 'partner@example.com'), $admin, false);
$apply('Room Ready Staging', 'pending-partner@example.com');

// A support ticket with a staff reply.
[$ticket] = Tickets::open(['name' => 'Mia Member', 'email' => 'member@example.com', 'subject' => 'Invoice question', 'body' => 'Can I get a copy of my March invoice?', 'category' => 'billing'], $member);
Tickets::staffReply($ticket, $admin, 'Hi Mia, you can download every invoice under Billing. I’ve marked this as solved.', false, 'solved');

Artisan::call('cache:clear');
echo "Seeded screens.\n";
