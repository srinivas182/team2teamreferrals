<?php

declare(strict_types=1);

namespace App\Domains\Network\Training;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Industries → Training: edit lessons and questions, ask for re-training, and see completion per group. */
final class AdminTrainingController
{
    public function __construct(private readonly Training $training) {}

    public function show(Industry $industry): Response
    {
        $m = $this->training->module($industry);

        return Inertia::render('admin/industries/Training', [
            'industry' => ['slug' => $industry->slug, 'name' => $industry->name, 'locked' => $industry->training_locked],
            'module' => ['version' => (int) $m->version, 'minVersion' => (int) $m->min_version, 'passMark' => (int) $m->pass_mark, 'lessons' => json_decode((string) $m->lessons, true), 'questions' => json_decode((string) $m->questions, true)],
            'completion' => Group::query()->where('industry_id', $industry->id)->orderBy('name')->get()->map(function (Group $g) use ($industry) {
                $leaders = DB::table('seats')->join('teams', 'teams.id', '=', 'seats.primary_team_id')->where('seats.group_id', $g->id)->pluck('teams.leader_id')->unique();
                $passed = $leaders->filter(fn ($id) => ($u = \App\Domains\Identity\Models\User::query()->find((int) $id)) instanceof \App\Domains\Identity\Models\User && $this->training->passed($u, $industry))->count();

                return ['name' => $g->name, 'required' => Training::required($g), 'holders' => $leaders->count(), 'passed' => $passed];
            }),
            'status' => session('status'),
            'seo' => SeoData::private('Training · '.$industry->name)->toArray(),
        ]);
    }

    public function save(Request $request, Industry $industry): RedirectResponse
    {
        $d = $request->validate([
            'pass_mark' => ['required', 'integer', 'min:50', 'max:100'],
            'retrain' => ['boolean'],
            'lessons' => ['required', 'array', 'min:1', 'max:20'],
            'lessons.*.title' => ['required', 'string', 'max:120'],
            'lessons.*.minutes' => ['required', 'integer', 'min:1', 'max:30'],
            'lessons.*.body' => ['required', 'string', 'max:5000'],
            'lessons.*.video_url' => ['nullable', 'url', 'max:500'],
            'lessons.*.transcript' => ['nullable', 'string', 'max:20000'],
            'questions' => ['required', 'array', 'min:1', 'max:30'],
            'questions.*.q' => ['required', 'string', 'max:300'],
            'questions.*.options' => ['required', 'array', 'min:2', 'max:6'],
            'questions.*.options.*' => ['required', 'string', 'max:200'],
            'questions.*.answer' => ['required', 'integer', 'min:0'],
            'questions.*.why' => ['required', 'string', 'max:500'],
        ], ['questions.*.why.required' => 'Every question needs an explanation: it’s what members see when they get it wrong.']);
        foreach ($d['questions'] as $i => $q) {
            if ($q['answer'] >= count($q['options'])) {
                throw ValidationException::withMessages(["questions.{$i}.answer" => 'Pick one of the options as the right answer.']);
            }
        }
        foreach ($d['lessons'] as $i => $l) {
            if (! empty($l['video_url']) && empty($l['transcript'])) {
                throw ValidationException::withMessages(["lessons.{$i}.transcript" => 'Add a transcript for every video, for accessibility and people watching on mute.']);
            }
        }
        $m = $this->training->module($industry);
        $version = (int) $m->version + 1;
        DB::table('training_modules')->where('id', $m->id)->update([
            'lessons' => json_encode(array_values($d['lessons'])), 'questions' => json_encode(array_values($d['questions'])), 'pass_mark' => $d['pass_mark'],
            'version' => $version, 'min_version' => ($d['retrain'] ?? false) ? $version : (int) $m->min_version, 'updated_at' => now(),
        ]);
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('training-saved')->withProperties(['version' => $version, 'retrain' => (bool) ($d['retrain'] ?? false)])->log("Saved {$industry->name} training v{$version}");

        return back()->with('status', ($d['retrain'] ?? false) ? 'training-saved-retrain' : 'training-saved');
    }
}
