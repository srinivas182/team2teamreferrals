<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\ConfirmNewEmailNotification;
use App\Domains\Identity\Notifications\EmailChangedNotification;
use App\Domains\Identity\Phone\PhoneNumber;
use App\Domains\Seo\SeoData;
use App\Support\Sms\Texting;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My account: name, mobile and time zone change straight away; a new email only after its owner clicks the link sent
 * to it (and the old address is told), so a session someone else got hold of can't quietly take the account over.
 */
final class AccountController
{
    /** Time zones offered first (the platform is US-only); any valid zone is accepted. */
    public const TIMEZONES = [
        'America/New_York' => 'Eastern', 'America/Chicago' => 'Central', 'America/Denver' => 'Mountain', 'America/Phoenix' => 'Arizona',
        'America/Los_Angeles' => 'Pacific', 'America/Anchorage' => 'Alaska', 'Pacific/Honolulu' => 'Hawaii',
    ];

    public function show(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('app/settings/Account', [
            'account' => [
                'first_name' => $user->first_name, 'last_name' => $user->last_name, 'email' => $user->email,
                'phone' => $user->phone, 'phoneVerified' => $user->phone_verified_at !== null, 'timezone' => $user->timezone,
            ],
            'timezones' => collect(self::TIMEZONES + [$user->timezone => $user->timezone])->map(fn ($label, $id) => ['id' => $id, 'label' => $label])->values(),
            'texting' => Texting::on(),
            'status' => session('status'),
            'seo' => SeoData::private('My account')->toArray(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && PhoneNumber::normalize((string) $value) === null) {
                    $fail('Enter a valid US mobile number.');
                }
            }],
            'timezone' => ['required', 'timezone:all'],
        ], ['timezone.timezone' => 'Choose a time zone from the list.']);
        $phone = filled($data['phone'] ?? null) ? PhoneNumber::normalize((string) $data['phone']) : null;

        $phoneChanged = $phone !== $user->phone;
        $user->fill(['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'timezone' => $data['timezone'], 'phone' => $phone]);
        if ($phoneChanged) {
            $user->phone_verified_at = null; // a new number is confirmed by text before it's used
        }
        $user->save(); // the activity log records what changed

        // With texting on, a new mobile number is confirmed by a code before the member area opens again.
        if ($phoneChanged && $phone !== null && Texting::on()) {
            return redirect()->route('verification.phone');
        }

        return back()->with('status', 'account-updated');
    }

    /** Ask to move to a new email: nothing changes until the link sent to it is clicked. */
    public function requestEmailChange(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique(User::class)->ignore($user->id), Rule::notIn([$user->email])],
            'password' => ['required', 'string'],
        ], ['email.unique' => 'Another account already uses this email.', 'email.not_in' => 'That’s already your email.']);
        if (! Hash::check($data['password'], (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'That password isn’t right.']);
        }

        $link = URL::temporarySignedRoute('settings.account.email.confirm', now()->addDay(), [
            'user' => $user, 'email' => $data['email'], 'from' => hash('sha256', (string) $user->email),
        ]);
        Notification::route('mail', $data['email'])->notify(new ConfirmNewEmailNotification($user->first_name, $link));
        activity('account')->causedBy($user)->performedOn($user)->event('email-change-requested')->withProperties(['to' => $data['email']])->log('Asked to change email');

        return back()->with('status', 'email-change-sent');
    }

    /** The link from the new address: switch, and tell the old address. */
    public function confirmEmailChange(Request $request, User $user): RedirectResponse
    {
        $email = (string) $request->query('email');
        // The link only works while the account still has the email it was sent from, and once.
        abort_unless(hash_equals((string) $request->query('from'), hash('sha256', (string) $user->email)), 410, 'This link has already been used or is out of date.');
        if (User::query()->where('email', $email)->whereKeyNot($user->id)->exists()) {
            abort(409, 'Another account already uses this email.');
        }

        $old = (string) $user->email;
        $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();
        Notification::route('mail', $old)->notify(new EmailChangedNotification($user->first_name, $email));

        return redirect($request->user()?->is($user) ? route('settings.account') : route('login'))->with('status', 'email-changed');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
