<?php

declare(strict_types=1);

namespace App\Domains\Network\Training\Video;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Training\Training;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * Training video studio. Platform admins work at /admin/training/{industry}/videos (the industry default, by lesson);
 * group owners at /app/group/{group}/training-video (their network's own video).
 */
final class VideoStudioController
{
    public function __construct(private readonly VideoStudio $studio, private readonly HeyGen $heygen) {}

    public function page(Request $request): Response
    {
        [$industry, $group, $base] = $this->context($request);
        $user = $this->user($request);
        abort_unless($this->studio->canManage($user, $industry, $group), 403);
        $disk = Storage::disk(config('filesystems.default'));
        $scripts = DB::table('video_scripts')->where(['industry_id' => $industry->id, 'group_id' => $group?->id])->orderByDesc('id')->get()
            ->map(fn ($s) => ['id' => (string) $s->ulid, 'title' => (string) $s->title, 'script' => (string) $s->script, 'status' => (string) $s->status, 'lesson' => $s->lesson_index,
                'estimate' => $this->studio->estimate($s), 'video' => $s->video_path ? $disk->url((string) $s->video_path) : null, 'captions' => $s->captions_path ? $disk->url((string) $s->captions_path) : null,
                'retake' => (bool) $s->retake, 'publishedAt' => $s->published_at ? substr((string) $s->published_at, 0, 10) : null])->values();
        $configured = $this->heygen->configured();

        return Inertia::render('training/VideoStudio', [
            'scope' => ['industry' => $industry->name, 'network' => $group?->name, 'restricted' => $industry->tier !== Tier::Open, 'isStaff' => $user->isStaff()],
            'base' => $base, 'scripts' => $scripts, 'configured' => $configured,
            'lessons' => $group === null ? array_map(fn ($l) => (string) $l['title'], (array) json_decode((string) app(Training::class)->module($industry)->lessons, true)) : [],
            'avatars' => $configured ? rescue(fn () => $this->heygen->avatars(), [], false) : [], 'voices' => $configured ? rescue(fn () => $this->heygen->voices(), [], false) : [],
            'draft' => session('script_draft'), 'status' => session('status'),
            'seo' => SeoData::private('Training videos')->toArray(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$industry, $group] = $this->context($request);
        $d = $request->validate(['title' => ['required', 'string', 'max:140'], 'script' => ['required', 'string', 'min:20', 'max:6000'], 'lesson' => ['nullable', 'integer', 'min:0']]);
        $this->studio->create($this->user($request), $industry, $group, isset($d['lesson']) ? (int) $d['lesson'] : null, (string) $d['title'], (string) $d['script']);

        return back()->with('status', 'video-script-saved');
    }

    public function draft(Request $request): RedirectResponse
    {
        [$industry, $group] = $this->context($request);
        $text = $this->studio->draftScript($this->user($request), $industry, $group);

        return back()->with($text !== null ? ['script_draft' => $text] : ['status' => 'video-draft-unavailable']);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:140'], 'script' => ['required', 'string', 'min:20', 'max:6000']]);
        $this->studio->update($this->user($request), $this->script($request), (string) $d['title'], (string) $d['script']);

        return back()->with('status', 'video-script-saved');
    }

    public function act(Request $request): RedirectResponse
    {
        $s = $this->script($request);
        $user = $this->user($request);
        match ((string) $request->route('action')) {
            'submit' => $this->studio->submit($user, $s),
            'approve' => $this->studio->approve($user, $s),
            'render' => $this->studio->render($user, $s, (string) $request->validate(['avatar' => ['required', 'string']])['avatar'], (string) $request->validate(['voice' => ['required', 'string']])['voice']),
            'publish' => $this->studio->publish($user, $s, $request->boolean('retake')),
            'rollback' => $this->studio->rollback($user, $s),
            default => abort(404),
        };

        return back()->with('status', 'video-'.$request->route('action'));
    }

    /** HeyGen tells us a render finished. Signed with the webhook secret; we re-check the status ourselves either way. */
    public function webhook(Request $request, Settings $settings): HttpResponse
    {
        $secret = (string) $settings->get('video.heygen.webhook_secret');
        abort_unless($secret !== '' && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('Signature')), 403);
        $id = $request->input('event_data.video_id');
        if (is_string($id) && ($render = DB::table('video_renders')->where('provider_video_id', $id)->first()) !== null) {
            $this->studio->refresh($render);
        }

        return response('', 204);
    }

    /** @return array{0: Industry, 1: ?Group, 2: string} */
    private function context(Request $request): array
    {
        // These actions don't type-hint the network, so resolve it from its web name here.
        $slug = $request->route('group');
        if ($slug !== null) {
            $group = $slug instanceof Group ? $slug : Group::query()->with('industry')->where('slug', (string) $slug)->firstOrFail();

            return [$group->industry, $group, '/app/group/'.$group->slug.'/training-video'];
        }
        $industry = Industry::query()->where('slug', (string) $request->route('industry'))->firstOrFail();

        return [$industry, null, '/admin/training/'.$industry->slug.'/videos'];
    }

    private function script(Request $request): stdClass
    {
        [$industry, $group] = $this->context($request);
        $s = DB::table('video_scripts')->where(['ulid' => (string) $request->route('script'), 'industry_id' => $industry->id, 'group_id' => $group?->id])->first();
        abort_if($s === null, 404);

        return $s;
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
