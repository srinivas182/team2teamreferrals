<?php

declare(strict_types=1);

namespace App\Domains\Network\Companies;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\SeatInvites;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Company admin: branches, every seat across them, who pays, and inviting city leaders in bulk. */
final class CompanyController
{
    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $company = Company::administeredBy($user);
        if ($company === null) {
            return Inertia::render('app/Company', ['company' => null, 'seo' => SeoData::private('Company')->toArray()]);
        }
        $branchIds = $company->branches()->pluck('id');

        return Inertia::render('app/Company', [
            'company' => ['name' => $company->name, 'id' => $company->ulid],
            'branches' => $company->branches()->with('leader:id,first_name,last_name,email')->orderBy('name')->get()
                ->map(fn (Team $t) => ['id' => $t->ulid, 'name' => $t->name, 'leader' => $t->leader->name, 'email' => $t->leader->email]),
            'seats' => DB::table('seats')->join('teams', 'teams.id', '=', 'seats.primary_team_id')->join('groups', 'groups.id', '=', 'seats.group_id')
                ->whereIn('seats.primary_team_id', $branchIds)->orderBy('seats.territory_name')
                ->get(['seats.id', 'seats.territory_name', 'seats.status', 'seats.payer', 'seats.trial_ends_at', 'seats.founding', 'teams.name as branch', 'groups.name as group'])
                ->map(fn ($s) => ['id' => (int) $s->id, 'territory' => (string) $s->territory_name, 'status' => (string) $s->status, 'payer' => (string) $s->payer, 'trialEnds' => $s->trial_ends_at ? substr((string) $s->trial_ends_at, 0, 10) : null,
                    'founding' => (bool) $s->founding, 'branch' => (string) $s->branch, 'group' => (string) $s->group]),
            'waiting' => DB::table('seat_waitlist')->join('seats', 'seats.id', '=', 'seat_waitlist.seat_id')->join('teams', 'teams.id', '=', 'seat_waitlist.team_id')
                ->whereIn('seat_waitlist.team_id', $branchIds)->get(['seats.id', 'seats.territory_name', 'teams.name as branch'])
                ->map(fn ($w) => ['territory' => (string) $w->territory_name, 'branch' => (string) $w->branch, 'position' => (int) DB::table('seat_waitlist')->where('seat_id', $w->id)->count()]),
            'privateNetworks' => Group::query()->where('company_id', $company->id)->get(['slug', 'name'])->map(fn (Group $g) => ['slug' => $g->slug, 'name' => $g->name]),
            'groups' => Group::query()->where('is_private', false)->orderBy('name')->get(['slug', 'name'])->map(fn (Group $g) => ['slug' => $g->slug, 'name' => $g->name]),
            'status' => session('status'),
            'note' => session('companyNote'),
            'seo' => SeoData::private($company->name)->toArray(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_if(Company::administeredBy($user) !== null, 422, 'You already run a company.');
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:160']])['name'];
        $company = Company::create(['name' => $name, 'admin_id' => $user->id]);
        $team = Team::of($user) ?? Team::forLeader($user);
        if ($team->company_id === null) {
            $team->forceFill(['company_id' => $company->id])->save();
        }

        return back()->with('status', 'company-created');
    }

    public function payer(Request $request, int $seat): RedirectResponse
    {
        $company = $this->company($request);
        $payer = (string) $request->validate(['payer' => ['required', 'in:team,company']])['payer'];
        $n = DB::table('seats')->where('id', $seat)->whereIn('primary_team_id', $company->branches()->pluck('id'))->update(['payer' => $payer, 'updated_at' => now()]);
        abort_if($n === 0, 404);
        activity('company')->causedBy($request->user())->event('seat-payer-changed')->withProperties(['seat' => $seat, 'payer' => $payer])->log("Seat {$seat} now paid by the {$payer}");

        return back()->with('status', 'payer-changed');
    }

    /** Invite city leaders (and optionally offer each a seat) in one go. */
    public function invite(Request $request, SeatInvites $seatInvites): RedirectResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:100'],
            'rows.*.email' => ['required', 'email'],
            'rows.*.group' => ['nullable', 'string', 'exists:groups,slug'],
            'rows.*.city_id' => ['nullable', 'integer', 'exists:cities,id', 'required_with:rows.*.group'],
        ]);
        $sent = 0;
        foreach ((array) $data['rows'] as $row) {
            $email = strtolower((string) $row['email']);
            $token = Str::random(48);
            DB::table('company_invites')->insert(['company_id' => $company->id, 'email' => $email, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(14), 'created_at' => now(), 'updated_at' => now()]);
            $url = url('/company-invites/'.$token);
            Notification::route('mail', $email)->notifyNow(new class($company->name, $url) extends \Illuminate\Notifications\Notification
            {
                public function __construct(public string $company, public string $url) {}

                /** @return list<string> */
                public function via(object $n): array
                {
                    return ['mail'];
                }

                public function toMail(object $n): MailMessage
                {
                    return (new MailMessage)->subject("Run a {$this->company} branch on Team to Team")->line("{$this->company} added you as a city leader.")->action('Join as a city leader', $this->url)->line('Valid for 14 days.');
                }
            });
            if (! empty($row['group']) && ! empty($row['city_id'])) {
                $group = Group::query()->where('slug', $row['group'])->firstOrFail();
                $seatInvites->send($group, [['email' => $email, 'city_id' => (int) $row['city_id']]], $this->user($request));
            }
            $sent++;
        }

        return back()->with('status', 'company-invites-sent')->with('companyNote', "{$sent} city leader invitation(s) sent.");
    }

    /** Public: shows the company; after sign-in, comes back here to accept. */
    public function invitation(Request $request, string $token): Response
    {
        if ($request->user() === null) {
            $request->session()->put('url.intended', url('/company-invites/'.$token));
        }
        $invite = DB::table('company_invites')->join('companies', 'companies.id', '=', 'company_invites.company_id')->where('token_hash', hash('sha256', $token))->where('status', 'pending')->where('expires_at', '>', now())->first(['company_invites.email', 'companies.name']);

        return Inertia::render('CompanyInvitation', [
            'invite' => $invite ? ['token' => $token, 'email' => (string) $invite->email, 'company' => (string) $invite->name] : null,
            'seo' => (new SeoData(title: 'Join a company', description: 'Team to Team Referrals', index: false))->toArray(),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = $this->user($request);
        $invite = DB::table('company_invites')->where('token_hash', hash('sha256', $token))->where('status', 'pending')->where('expires_at', '>', now())->first();
        if ($invite === null || strtolower($user->email) !== strtolower((string) $invite->email)) {
            throw ValidationException::withMessages(['invite' => 'This invitation has expired, was already used, or was sent to another email.']);
        }
        $team = Team::of($user) ?? Team::forLeader($user);
        if ($team->company_id !== null && $team->company_id !== (int) $invite->company_id) {
            throw ValidationException::withMessages(['invite' => 'Your team already belongs to another company.']);
        }
        $team->forceFill(['company_id' => (int) $invite->company_id])->save();
        DB::table('company_invites')->where('id', $invite->id)->update(['status' => 'accepted', 'updated_at' => now()]);

        return redirect()->to('/app/team')->with('status', 'company-joined');
    }

    private function company(Request $request): Company
    {
        $c = Company::administeredBy($this->user($request));
        abort_if($c === null, 403);

        return $c;
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
