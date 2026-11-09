<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Actions\PasswordSetupToken;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\StaffInvitationNotification;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class StaffController
{
    public function index(): Response
    {
        $staff = User::role([Permissions::SUPER_ADMIN, Permissions::STAFF])->with(['roles', 'permissions'])->orderBy('first_name')->get();

        return Inertia::render('admin/staff/Index', [
            'staff' => $staff->map(fn (User $u) => [
                'id' => $u->ulid,
                'name' => $u->name,
                'email' => $u->email,
                'superAdmin' => $u->hasRole(Permissions::SUPER_ADMIN),
                'permissions' => $u->getDirectPermissions()->pluck('name')->values(),
                'twoFactor' => $u->hasTwoFactorEnabled(),
                'invited' => $u->last_login_at === null,
                'lastLogin' => $u->last_login_at?->diffForHumans(),
                'active' => $u->is_active,
            ]),
            'catalogue' => Permissions::CATALOGUE,
            'status' => session('status'),
            'seo' => SeoData::private('Staff')->toArray(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique(User::class)],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Permissions::all())],
        ], ['email.unique' => 'Someone already uses this email. Promote them from their account instead.']);

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => strtolower((string) $data['email']),
            'password' => Str::password(48),
        ]);
        $user->assignRole(Permissions::STAFF);
        $user->syncPermissions($data['permissions'] ?? []);
        $user->notify(new StaffInvitationNotification(PasswordSetupToken::for($user), (string) $request->user()?->getAttribute('first_name')));
        activity('admin')->causedBy($request->user())->performedOn($user)->event('staff-invited')
            ->withProperties(['permissions' => $data['permissions'] ?? []])->log('Invited '.$user->name.' as staff');

        return back()->with('status', 'staff-invited');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->guardTarget($request, $user);
        $data = $request->validate(['permissions' => ['array'], 'permissions.*' => [Rule::in(Permissions::all())]]);
        $before = $user->getDirectPermissions()->pluck('name')->sort()->values()->all();
        $user->syncPermissions($data['permissions'] ?? []);
        /** @var list<string> $after */
        $after = $data['permissions'] ?? [];
        sort($after);
        activity('admin')->causedBy($request->user())->performedOn($user)->event('permissions-changed')
            ->withProperties(['old' => $before, 'attributes' => $after])->log('Changed permissions for '.$user->name);

        return back()->with('status', 'permissions-saved');
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        $this->guardTarget($request, $user);
        $user->forceFill(['is_active' => ! $user->is_active])->save();
        activity('admin')->causedBy($request->user())->performedOn($user)->event($user->is_active ? 'staff-reactivated' : 'staff-deactivated')
            ->log(($user->is_active ? 'Reactivated ' : 'Deactivated ').$user->name);

        return back()->with('status', $user->is_active ? 'staff-reactivated' : 'staff-deactivated');
    }

    /** Nobody edits themselves here, and only super admins can change other super admins. */
    private function guardTarget(Request $request, User $target): void
    {
        $actor = $request->user();
        abort_if($actor instanceof User && $actor->is($target), 403, 'You can’t change your own access.');
        abort_if($target->hasRole(Permissions::SUPER_ADMIN) && ! ($actor instanceof User && $actor->hasRole(Permissions::SUPER_ADMIN)), 403);
        abort_unless($target->isStaff(), 404);
        Gate::authorize('staff.manage');
    }
}
