<?php

declare(strict_types=1);

namespace App\Domains\Network\Teams;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/** The team: profile (leader and assistants edit), members and invitations (leader only). */
final class TeamController
{
    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $team = Team::of($user) ?? Team::forLeader($user);
        $role = (string) $team->roleOf($user);

        return Inertia::render('app/Team', [
            'team' => ['id' => $team->ulid, 'name' => $team->name, 'bio' => $team->bio, 'website' => $team->website, 'phone' => $team->phone, 'social' => $team->social ?? new stdClass,
                'serviceArea' => $team->service_area, 'foundedYear' => $team->founded_year, 'logo' => $team->logo_path ? Storage::disk('public')->url($team->logo_path) : null],
            'role' => $role,
            'canEdit' => in_array($role, ['leader', 'assistant'], true),
            'canManage' => $role === 'leader',
            'members' => $team->members()->orderBy('first_name')->get()->map(fn (User $m) => ['id' => $m->ulid, 'name' => $m->name, 'email' => $m->email, 'role' => (string) $m->getRelationValue('pivot')->role,
                'trained' => collect(app(\App\Domains\Network\Training\Training::class)->industriesFor($user))->every(fn ($i) => app(\App\Domains\Network\Training\Training::class)->passed($m, $i))]),
            'teamTrained' => app(\App\Domains\Network\Training\Training::class)->teamTrained($team),
            'invites' => $role === 'leader' ? DB::table('team_invites')->where(['team_id' => $team->id, 'status' => 'pending'])->where('expires_at', '>', now())->get(['id', 'email', 'role', 'expires_at'])
                ->map(fn ($i) => ['id' => (int) $i->id, 'email' => (string) $i->email, 'role' => (string) $i->role, 'expires' => (string) $i->expires_at]) : [],
            'roles' => Team::ROLES,
            'quickReplies' => \App\Domains\Referrals\Conversations::quickReplies($team),
            'showDealValue' => (bool) DB::table('teams')->where('id', $team->id)->value('show_deal_value'),
            'specialties' => json_decode((string) DB::table('teams')->where('id', $team->id)->value('specialties'), true) ?: [],
            'languages' => json_decode((string) DB::table('teams')->where('id', $team->id)->value('languages'), true) ?: [],
            // The specialty choices come from the industry families of the networks this team is in.
            'specialtyOptions' => \App\Domains\Community\Directory\Specialties::forTeam($team->id),
            'languageOptions' => \App\Domains\Community\Directory\Specialties::LANGUAGES,
            'status' => session('status'),
            'seo' => SeoData::private('Team')->toArray(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        [$team] = $this->teamFor($request, ['leader', 'assistant']);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'url', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'service_area' => ['nullable', 'string', 'max:500'],
            'founded_year' => ['nullable', 'integer', 'min:1900', 'max:'.date('Y')],
            'social' => ['nullable', 'array'],
            'social.*' => ['nullable', 'url', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'show_deal_value' => ['sometimes', 'boolean'],
            'specialties' => ['sometimes', 'array', 'max:12'], 'specialties.*' => ['string', 'max:40'],
            'languages' => ['sometimes', 'array', 'max:12'], 'languages.*' => ['string', Rule::in(\App\Domains\Community\Directory\Specialties::LANGUAGES)],
        ]);
        foreach (['specialties', 'languages'] as $k) {
            if (array_key_exists($k, $data)) {
                DB::table('teams')->where('id', $team->id)->update([$k => json_encode(array_values(array_unique((array) $data[$k])))]);
                unset($data[$k]);
            }
        }
        if (array_key_exists('show_deal_value', $data)) {
            DB::table('teams')->where('id', $team->id)->update(['show_deal_value' => (bool) $data['show_deal_value']]);
            unset($data['show_deal_value']);
        }
        if ($request->hasFile('logo')) {
            $team->logo_path = $request->file('logo')?->store('teams', 'public') ?: $team->logo_path;
        }
        $social = array_filter(array_intersect_key((array) ($data['social'] ?? []), array_flip(['linkedin', 'facebook', 'instagram', 'youtube', 'x'])));
        unset($data['logo'], $data['social']);
        $team->fill([...$data, 'social' => $social ?: null])->save();
        activity('team')->causedBy($request->user())->performedOn($team)->event('team-updated')->log("Updated team profile {$team->name}");

        return back()->with('status', 'team-saved');
    }

    public function invite(Request $request): RedirectResponse
    {
        [$team, $user] = $this->teamFor($request, ['leader']);
        $data = $request->validate(['email' => ['required', 'email'], 'role' => ['required', Rule::in(['member', 'assistant'])]]);
        $email = strtolower((string) $data['email']);
        if ($team->members()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'They’re already on your team.']);
        }
        $token = Str::random(48);
        DB::table('team_invites')->insert(['team_id' => $team->id, 'email' => $email, 'role' => $data['role'], 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(14), 'invited_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        Notification::route('mail', $email)->notify(new TeamInviteNotification($team->name, (string) $data['role'], url('/team-invites/'.$token), $user->name));

        return back()->with('status', 'team-invite-sent');
    }

    public function removeMember(Request $request, User $member): RedirectResponse
    {
        [$team, $user] = $this->teamFor($request, ['leader']);
        if ($member->id === $user->id) {
            throw ValidationException::withMessages(['member' => 'The leader can’t remove themselves. Hand over leadership first (coming with companies).']);
        }
        $team->members()->detach($member->id);
        activity('team')->causedBy($user)->performedOn($team)->event('team-member-removed')->withProperties(['member' => $member->ulid])->log("Removed {$member->name} from {$team->name}");

        return back()->with('status', 'team-member-removed');
    }

    public function revokeInvite(Request $request, int $invite): RedirectResponse
    {
        [$team] = $this->teamFor($request, ['leader']);
        DB::table('team_invites')->where(['id' => $invite, 'team_id' => $team->id])->update(['status' => 'revoked', 'updated_at' => now()]);

        return back()->with('status', 'team-invite-revoked');
    }

    /** Public: shows who invited them; after sign-in, comes back here to accept. */
    public function invitation(Request $request, string $token): Response
    {
        if ($request->user() === null) {
            $request->session()->put('url.intended', url('/team-invites/'.$token));
        }
        $i = $this->pending($token);

        return Inertia::render('TeamInvitation', [
            'invite' => $i ? ['token' => $token, 'email' => (string) $i->email, 'role' => (string) $i->role, 'team' => (string) Team::query()->whereKey($i->team_id)->value('name')] : null,
            'seo' => (new SeoData(title: 'Join a team', description: 'Team to Team Referrals', index: false))->toArray(),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = $this->user($request);
        $i = $this->pending($token);
        if ($i === null) {
            throw ValidationException::withMessages(['invite' => 'This invitation has expired or was already used.']);
        }
        if (strtolower($user->email) !== strtolower((string) $i->email)) {
            throw ValidationException::withMessages(['invite' => 'This invitation was sent to '.$i->email.'. Sign in with that email to accept it.']);
        }
        DB::table('team_members')->insertOrIgnore(['team_id' => $i->team_id, 'user_id' => $user->id, 'role' => $i->role, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('team_invites')->where('id', $i->id)->update(['status' => 'accepted', 'updated_at' => now()]);

        return redirect()->to('/app/team')->with('status', 'team-joined');
    }

    private function pending(string $token): ?stdClass
    {
        /** @var ?stdClass $row */
        $row = DB::table('team_invites')->where('token_hash', hash('sha256', $token))->where('status', 'pending')->where('expires_at', '>', now())->first();

        return $row;
    }

    /**
     * @param  list<string>  $roles
     * @return array{0: Team, 1: User}
     */
    private function teamFor(Request $request, array $roles): array
    {
        $user = $this->user($request);
        // A new pro's team of one is created the first time they need it, whichever screen they start from.
        $team = Team::of($user) ?? Team::forLeader($user);
        abort_unless(in_array($team->roleOf($user), $roles, true), 403);

        return [$team, $user];
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
