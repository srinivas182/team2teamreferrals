<?php

declare(strict_types=1);

namespace App\Domains\Network\Training;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class TrainingController
{
    public function __construct(private readonly Training $training) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('app/training/Index', [
            'trainings' => array_map(function (Industry $i) use ($user) {
                $m = $this->training->module($i);
                $p = $this->training->progress($user, $m);
                $lessons = (array) json_decode((string) $m->lessons, true);

                return ['industry' => $i->slug, 'name' => $i->name, 'lessons' => count($lessons), 'minutes' => array_sum(array_column($lessons, 'minutes')),
                    'done' => $p ? count((array) json_decode((string) $p->lessons_done, true)) : 0, 'passed' => $this->training->passed($user, $i)];
            }, $this->training->industriesFor($user)),
            'seo' => SeoData::private('Training')->toArray(),
        ]);
    }

    public function show(Request $request, Industry $industry): Response
    {
        $user = $this->user($request);
        $m = $this->training->module($industry);
        $p = $this->training->progress($user, $m);
        /** @var list<array{q: string, options: list<string>, answer: int, why: string}> $qs */
        $qs = json_decode((string) $m->questions, true);
        $missed = $p && $p->missed ? array_map('intval', (array) json_decode((string) $p->missed, true)) : null;

        return Inertia::render('app/training/Show', [
            'industry' => ['slug' => $industry->slug, 'name' => $industry->name],
            'lessons' => json_decode((string) $m->lessons, true),
            // Videos your networks made for this training (AI presenter), shown before the lessons.
            'networkVideos' => DB::table('video_scripts as v')->join('groups as g', 'g.id', '=', 'v.group_id')->where(['v.industry_id' => $industry->id, 'v.status' => 'published'])
                ->whereIn('v.group_id', DB::table('seats')->where('primary_team_id', ($t = $request->user() !== null ? \App\Domains\Network\Seats\Models\Team::of($request->user()) : null) !== null ? $t->id : 0)->pluck('group_id'))
                ->get(['v.title', 'v.script', 'v.video_path', 'v.captions_path', 'g.name'])->map(fn ($v) => ['network' => (string) $v->name, 'title' => (string) $v->title, 'transcript' => (string) $v->script,
                    'url' => \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'))->url((string) $v->video_path),
                    'captions' => $v->captions_path ? \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'))->url((string) $v->captions_path) : null])->values(),
            'done' => $p ? array_map('intval', (array) json_decode((string) $p->lessons_done, true)) : [],
            // Answers stay on the server; retakes show only the questions missed last time.
            'questions' => array_values(array_map(fn ($i) => ['index' => $i, 'q' => $qs[$i]['q'], 'options' => $qs[$i]['options']], $missed ?? array_keys($qs))),
            'retake' => $missed !== null,
            'passMark' => (int) $m->pass_mark,
            'passed' => $this->training->passed($user, $industry),
            'result' => session('trainingResult'),
            'seo' => SeoData::private('Training · '.$industry->name)->toArray(),
        ]);
    }

    public function lesson(Request $request, Industry $industry, int $index): RedirectResponse
    {
        $this->training->completeLesson($this->user($request), $this->training->module($industry), $index);

        return back();
    }

    public function submit(Request $request, Industry $industry): RedirectResponse
    {
        $answers = (array) $request->validate(['answers' => ['required', 'array']])['answers'];
        $result = $this->training->submit($this->user($request), $this->training->module($industry), $answers);
        $qs = (array) json_decode((string) $this->training->module($industry)->questions, true);
        $result['wrong'] = array_map(fn ($w) => [...$w, 'q' => $qs[$w['index']]['q'] ?? ''], $result['wrong']);

        return back()->with('trainingResult', $result);
    }

    /** Group owners switch the training gate. Turning it on starts a new grace period and tells every member. */
    public function ownerSwitch(Request $request, Group $group, Notifier $notifier): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($group->isOwnedBy($user), 403);
        $on = $request->boolean('required');
        if (! $on && $group->industry->training_locked) {
            throw ValidationException::withMessages(['required' => 'Training is required by the platform for this industry and can’t be switched off.']);
        }
        $was = $group->rule('training_required') === true;
        $group->forceFill(['rules' => [...$group->rules, 'training_required' => $on, 'training_since' => $on && ! $was ? now()->toDateString() : $group->rule('training_since')]])->save();
        activity('group')->causedBy($user)->performedOn($group)->event('training-switch')->withProperties(['required' => $on])->log(($on ? 'Required' : 'Made optional').' training in '.$group->name);
        if ($on && ! $was) {
            $days = (int) $group->rule('training_grace_days');
            $leaders = User::query()->whereIn('id', DB::table('seats')->join('teams', 'teams.id', '=', 'seats.primary_team_id')->where('seats.group_id', $group->id)->pluck('teams.leader_id'))->get();
            foreach ($leaders as $l) {
                $notifier->send($l, 'network.announcement', ['body' => "{$group->name} now requires the network training. You have {$days} days to finish it (about 15 minutes); after that, new referrals wait until you’ve passed."], url('/app/training/'.$group->industry->slug), $group->industry);
            }
        }

        return back()->with('status', $on ? 'training-required' : 'training-optional');
    }

    /** A completion certificate members can show their brokerage or carrier. */
    public function certificate(Request $request, Industry $industry): \Illuminate\Http\Response
    {
        $user = $this->user($request);
        abort_unless($this->training->passed($user, $industry), 404);
        $p = $this->training->progress($user, $this->training->module($industry));

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.training-certificate', ['name' => $user->name, 'industry' => $industry->name, 'date' => \Carbon\Carbon::parse((string) $p?->passed_at)->toFormattedDateString(), 'score' => (int) ($p->best_score ?? 0), 'version' => (int) ($p->passed_version ?? 1)])
            ->setPaper('letter', 'landscape')->download('network-training-'.$industry->slug.'.pdf');
    }

    /** The one-minute refresher shown before a member's first referral: deadlines, consent and the fee rule, in their words. */
    public function refresher(Request $request, Industry $industry): Response
    {
        $t = $industry->terms();
        DB::table('onboarding_nudges')->insertOrIgnore(['user_id' => $this->user($request)->id, 'kind' => 'refresher', 'sent_at' => now()]);

        return Inertia::render('app/training/Refresher', [
            'industry' => ['slug' => $industry->slug, 'name' => $industry->name],
            'points' => [
                "Only send a {$t['referral']} when your {$t['client']} has agreed to be contacted.",
                'Accept or decline within the group’s deadline, usually 24 hours, and make first contact quickly.',
                'Post an update at least every 7 days until it closes.',
                $industry->tier->allowsMemberReferralFees() ? "Any {$t['fee']} follows the signed agreement and is paid business to business." : "No fee or payment may pass between members for an {$t['referral']}, in any form.",
            ],
            'seo' => SeoData::private('Before your first referral')->toArray(),
        ]);
    }

    /** Team leaders nudge a member to finish their training. */
    public function remind(Request $request, User $member, Notifier $notifier): RedirectResponse
    {
        $leader = $this->user($request);
        $team = \App\Domains\Network\Seats\Models\Team::of($leader);
        abort_unless($team && $team->roleOf($leader) === 'leader' && $team->roleOf($member) !== null, 403);
        $notifier->send($member, 'training.reminder', ['headline' => "{$leader->first_name} asked you to finish your network training", 'body' => 'It takes about 15 minutes, and you can do it in short sittings on your phone.'], url('/app/training'));

        return back()->with('status', 'training-reminded');
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
