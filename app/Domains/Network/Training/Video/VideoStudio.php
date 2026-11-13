<?php

declare(strict_types=1);

namespace App\Domains\Network\Training\Video;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Training\Training;
use App\Domains\Platform\Ai\AiCredits;
use App\Domains\Platform\Ai\AiGateway;
use App\Domains\Platform\Ai\AiProvider;
use App\Domains\Platform\Ai\Prompts;
use App\Domains\Platform\Modules\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * AI training videos. Platform admins make the industry default (a lesson's video, as a new training version, free);
 * group owners make their network's own video (charged to their AI credits). Scripts can be AI-drafted from the
 * network's actual rules, are checked (no fee talk in Tier B, where approval needs a platform admin) and approved
 * before rendering. Renders use stock presenters only, retry once, and refund credits if they still fail. Finished
 * videos and captions are stored on our own storage. Publishing can require a retake; rollback is one step.
 */
final class VideoStudio
{
    public const WORDS_PER_MINUTE = 150;

    public const CREDITS_PER_MINUTE = 50;

    public const MAX_ATTEMPTS = 2;

    public function __construct(private readonly HeyGen $heygen, private readonly AiProvider $ai, private readonly AiGateway $gateway, private readonly Modules $modules, private readonly Training $training) {}

    public function canManage(User $user, Industry $industry, ?Group $group): bool
    {
        if (! $this->modules->enabled('ai_videos')) {
            return false;
        }

        return $group === null ? ($user->isStaff() && $user->can('industries.manage')) : ($group->isOwnedBy($user) || ($user->isStaff() && $user->can('industries.manage')));
    }

    public function create(User $user, Industry $industry, ?Group $group, ?int $lesson, string $title, string $script): string
    {
        abort_unless($this->canManage($user, $industry, $group), 403);
        if ($group === null && ($lesson === null || ! isset(json_decode((string) $this->training->module($industry)->lessons, true)[$lesson]))) {
            throw ValidationException::withMessages(['lesson' => 'Choose the lesson this video is for.']);
        }
        $ulid = (string) Str::ulid();
        DB::table('video_scripts')->insert(['ulid' => $ulid, 'industry_id' => $industry->id, 'group_id' => $group?->id, 'lesson_index' => $group === null ? $lesson : null,
            'title' => $title, 'script' => trim($script), 'status' => 'draft', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return $ulid;
    }

    /** An AI first draft from the network's (or industry's) actual rules; the member edits it before submitting. */
    public function draftScript(User $user, Industry $industry, ?Group $group): ?string
    {
        abort_unless($this->canManage($user, $industry, $group), 403);
        $vars = ['network' => $group !== null ? $group->name : $industry->name.' networks', 'facts' => self::facts($industry, $group)];
        if ($group !== null && ! $user->isStaff()) {
            $team = Team::of($user) ?? Team::forLeader($user);
            $r = $this->gateway->run($team, $user, 'training_video_script', 'training_video_script', $vars, 1, 900);

            return $r !== null ? trim($r['text']) : null;
        }
        if (! $this->ai->enabled()) {
            return null;
        }
        try { // platform admins: the platform pays
            $p = Prompts::active('training_video_script');

            return trim($this->ai->complete(Prompts::fill($p['template'], $vars), 'training_video_script', 900, Prompts::fill($p['system'], $vars))['text']);
        } catch (Throwable) {
            return null;
        }
    }

    public function update(User $user, stdClass $s, string $title, string $script): void
    {
        $this->authorise($user, $s);
        abort_unless(in_array($s->status, ['draft', 'submitted'], true), 422, 'Only drafts can be edited.');
        DB::table('video_scripts')->where('id', $s->id)->update(['title' => $title, 'script' => trim($script), 'status' => 'draft', 'updated_at' => now()]);
    }

    /** Submit for approval, after the compliance check. */
    public function submit(User $user, stdClass $s): void
    {
        $this->authorise($user, $s);
        abort_unless($s->status === 'draft', 422);
        $industry = Industry::query()->findOrFail((int) $s->industry_id);
        if ($industry->tier !== Tier::Open && preg_match('/\b(referral fees?|fee|commission split|kickback|paid for referrals?)\b/i', (string) $s->script)) {
            throw ValidationException::withMessages(['script' => 'This industry doesn’t allow referral fees, so the script can’t mention them. Explain that referrals are exchanged without payment.']);
        }
        if (self::words((string) $s->script) < 30) {
            throw ValidationException::withMessages(['script' => 'The script is too short for a training video (at least 30 words).']);
        }
        DB::table('video_scripts')->where('id', $s->id)->update(['status' => 'submitted', 'updated_at' => now()]);
    }

    /** Tier B scripts need a platform admin; others can be approved by the owner after reading them. */
    public function approve(User $user, stdClass $s): void
    {
        $this->authorise($user, $s);
        abort_unless($s->status === 'submitted', 422);
        $industry = Industry::query()->findOrFail((int) $s->industry_id);
        abort_if($industry->tier !== Tier::Open && ! $user->isStaff(), 403, 'In this industry, a platform admin approves training scripts.');
        DB::table('video_scripts')->where('id', $s->id)->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'updated_at' => now()]);
        activity('training')->causedBy($user)->event('video-script-approved')->withProperties(['script' => $s->ulid])->log('Training video script approved');
    }

    /** @return array{minutes: int, credits: int, usd: float} credits are 0 when the platform pays */
    public function estimate(stdClass $s): array
    {
        $minutes = max(1, (int) ceil(self::words((string) $s->script) / self::WORDS_PER_MINUTE));

        return ['minutes' => $minutes, 'credits' => $s->group_id === null ? 0 : $minutes * self::CREDITS_PER_MINUTE, 'usd' => round($minutes * $this->heygen->pricePerMinute(), 2)];
    }

    public function render(User $user, stdClass $s, string $avatarId, string $voiceId): void
    {
        $this->authorise($user, $s);
        abort_unless(in_array($s->status, ['approved', 'failed'], true), 422, 'Approve the script first.');
        abort_unless($this->heygen->configured(), 422, 'The video provider isn’t connected (Admin → Settings → Video).');
        // Stock presenters and voices only: never a custom likeness.
        if (! collect($this->heygen->avatars())->contains('id', $avatarId) || ! collect($this->heygen->voices())->contains('id', $voiceId)) {
            throw ValidationException::withMessages(['avatar' => 'Choose a presenter and voice from the list.']);
        }
        $cost = $this->estimate($s);
        $team = null;
        if ($cost['credits'] > 0) {
            $team = Team::of($user) ?? Team::forLeader($user);
            AiCredits::grantMonthly($team);
            if (AiCredits::balance($team) < $cost['credits']) {
                throw ValidationException::withMessages(['credits' => "This video needs {$cost['credits']} AI credits. You can buy more on the Billing page."]);
            }
        }
        $videoId = $this->heygen->render($avatarId, $voiceId, (string) $s->script, (string) $s->ulid);
        if ($team !== null) {
            $usage = DB::table('ai_usage_logs')->insertGetId(['team_id' => $team->id, 'user_id' => $user->id, 'feature' => 'training_video', 'provider' => 'heygen', 'credits' => $cost['credits'], 'cost_usd' => $cost['usd'], 'status' => 'ok', 'created_at' => now()]);
            AiCredits::charge($team, $cost['credits'], $usage);
        }
        DB::table('video_scripts')->where('id', $s->id)->update(['status' => 'rendering', 'avatar_id' => $avatarId, 'voice_id' => $voiceId, 'team_id' => $team?->id, 'credits' => $cost['credits'], 'updated_at' => now()]);
        DB::table('video_renders')->insert(['script_id' => $s->id, 'provider_video_id' => $videoId, 'status' => 'processing', 'attempt' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Check a render (from the callback or the 5-minute poll): store the finished video, retry once, refund on failure. */
    public function refresh(stdClass $render): void
    {
        $s = DB::table('video_scripts')->where('id', $render->script_id)->first();
        if ($s === null || $render->status !== 'processing') {
            return;
        }
        $st = $this->heygen->status((string) $render->provider_video_id);
        DB::table('video_renders')->where('id', $render->id)->update(['checked_at' => now()]);
        if ($st['status'] === 'processing') {
            return;
        }
        if ($st['status'] === 'completed' && is_string($st['video_url'])) {
            $disk = Storage::disk(config('filesystems.default'));
            $video = Http::timeout(120)->get($st['video_url']);
            if ($video->failed()) {
                return; // try again at the next check
            }
            $path = "training-videos/{$s->ulid}.mp4";
            $disk->put($path, $video->body());
            $captions = null;
            if (is_string($st['caption_url']) && ($c = Http::timeout(30)->get($st['caption_url']))->successful()) {
                $captions = "training-videos/{$s->ulid}.vtt";
                $disk->put($captions, $c->body());
            }
            DB::table('video_renders')->where('id', $render->id)->update(['status' => 'ready', 'updated_at' => now()]);
            DB::table('video_scripts')->where('id', $s->id)->update(['status' => 'ready', 'video_path' => $path, 'captions_path' => $captions, 'updated_at' => now()]);

            return;
        }
        DB::table('video_renders')->where('id', $render->id)->update(['status' => 'failed', 'error' => mb_substr((string) $st['error'], 0, 255), 'updated_at' => now()]);
        if ((int) $render->attempt < self::MAX_ATTEMPTS) {
            try { // one automatic retry
                $id = $this->heygen->render((string) $s->avatar_id, (string) $s->voice_id, (string) $s->script, (string) $s->ulid.'-'.((int) $render->attempt + 1));
                DB::table('video_renders')->insert(['script_id' => $s->id, 'provider_video_id' => $id, 'status' => 'processing', 'attempt' => (int) $render->attempt + 1, 'created_at' => now(), 'updated_at' => now()]);

                return;
            } catch (Throwable $e) {
                report($e);
            }
        }
        // Still failing: refund the credits and let the member try again.
        if ($s->team_id !== null && (int) $s->credits > 0) {
            DB::table('ai_credit_ledger')->insert(['team_id' => $s->team_id, 'kind' => 'adjust', 'credits' => (int) $s->credits, 'created_at' => now()]);
        }
        DB::table('video_scripts')->where('id', $s->id)->update(['status' => 'failed', 'credits' => 0, 'updated_at' => now()]);
    }

    /** Every 5 minutes: check renders still processing (backup for the callback). */
    public function poll(): void
    {
        foreach (DB::table('video_renders')->where('status', 'processing')->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinutes(4)))->get() as $r) {
            try {
                $this->refresh($r);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    public function publish(User $user, stdClass $s, bool $retake): void
    {
        $this->authorise($user, $s);
        abort_unless($s->status === 'ready', 422, 'Preview the finished video before publishing.');
        $url = Storage::disk(config('filesystems.default'))->url((string) $s->video_path);
        DB::transaction(function () use ($s, $retake, $url) {
            DB::table('video_scripts')->where(['industry_id' => $s->industry_id, 'group_id' => $s->group_id, 'lesson_index' => $s->lesson_index, 'status' => 'published'])
                ->update(['status' => 'retired', 'updated_at' => now()]);
            if ($s->group_id === null) {
                // Industry default: the lesson gets the video and the script as its transcript, as a new training version.
                $module = $this->training->module(Industry::query()->findOrFail((int) $s->industry_id));
                $lessons = (array) json_decode((string) $module->lessons, true);
                $previous = $lessons;
                $lessons[(int) $s->lesson_index] = [...$lessons[(int) $s->lesson_index], 'video_url' => $url, 'transcript' => (string) $s->script, 'ai_presenter' => true,
                    'captions_url' => $s->captions_path ? Storage::disk(config('filesystems.default'))->url((string) $s->captions_path) : null];
                $version = (int) $module->version + 1;
                DB::table('training_modules')->where('id', $module->id)->update(['lessons' => json_encode($lessons), 'version' => $version, 'min_version' => $retake ? $version : $module->min_version, 'updated_at' => now()]);
                DB::table('video_scripts')->where('id', $s->id)->update(['previous' => json_encode($previous)]);
            } elseif ($retake) {
                DB::table('groups')->where('id', $s->group_id)->update(['training_retake_at' => now()]);
            }
            DB::table('video_scripts')->where('id', $s->id)->update(['status' => 'published', 'retake' => $retake, 'published_at' => now(), 'updated_at' => now()]);
        });
        activity('training')->causedBy($user)->event('video-published')->withProperties(['script' => $s->ulid, 'retake' => $retake])->log('Training video published');
    }

    /** Undo a published video: the industry lesson goes back to how it was (a new version); a network falls back to its previous video, or the industry's. */
    public function rollback(User $user, stdClass $s): void
    {
        $this->authorise($user, $s);
        abort_unless($s->status === 'published', 422);
        DB::transaction(function () use ($s) {
            DB::table('video_scripts')->where('id', $s->id)->update(['status' => 'retired', 'updated_at' => now()]);
            if ($s->group_id === null && $s->previous !== null) {
                $module = $this->training->module(Industry::query()->findOrFail((int) $s->industry_id));
                DB::table('training_modules')->where('id', $module->id)->update(['lessons' => $s->previous, 'version' => (int) $module->version + 1, 'updated_at' => now()]);
            }
            $prior = DB::table('video_scripts')->where(['industry_id' => $s->industry_id, 'group_id' => $s->group_id, 'lesson_index' => $s->lesson_index, 'status' => 'retired'])
                ->where('id', '!=', $s->id)->whereNotNull('published_at')->orderByDesc('published_at')->first();
            if ($s->group_id !== null && $prior !== null) {
                DB::table('video_scripts')->where('id', $prior->id)->update(['status' => 'published', 'updated_at' => now()]);
            }
        });
        activity('training')->causedBy($user)->event('video-rolled-back')->withProperties(['script' => $s->ulid])->log('Training video rolled back');
    }

    /** Spoken words, numbers included ("24 hours" is two words aloud; str_word_count would skip the number). */
    public static function words(string $script): int
    {
        return count(preg_split('/\s+/u', trim($script), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** The rules a script should explain, from the network (or the industry's defaults). */
    public static function facts(Industry $industry, ?Group $group): string
    {
        $rule = fn (string $k) => $group !== null ? $group->rule($k) : null;
        $fee = $industry->tier === Tier::Open ? 'Referral fees are allowed when agreed in the referral agreement, as a share of the receiving team’s earnings from the referral.'
            : 'Referral fees are not allowed in this industry (RESPA): referrals are exchanged without payment, and members earn credits and recognition instead.';

        return implode("\n", [
            'Industry: '.$industry->name.($group !== null ? '; network: '.$group->name : ''),
            'Accept or decline a referral within '.($rule('accept_hours') ?? 24).' hours.',
            'Make first contact with the client within '.($rule('first_contact_hours') ?? 24).' hours of accepting.',
            'Update the referring team at least every '.($rule('update_days') ?? 7).' days.',
            'Only refer clients who have agreed to be referred; their details are private until the referral is accepted.',
            $fee,
            'Closing a referral: mark it won or lost; the referring team confirms the close.',
        ]);
    }

    /**
     * The written default script: free, needs no AI, and is built from the same rules as the network's training, so
     * every network can start from it. In restricted industries it never mentions fees (the submit check would refuse).
     */
    public static function defaultScript(Industry $industry, ?Group $group): string
    {
        $rule = fn (string $k, int $d) => $group !== null ? (int) ($group->rule($k) ?? $d) : $d;
        $name = $group !== null ? $group->name : 'the '.$industry->name.' network';
        $money = $industry->tier === Tier::Open
            ? 'If a fee is agreed, it is written into the referral agreement before you send the client, as a share of what the receiving team earns from the referral, never a share of the sale price. Teams pay each other directly.'
            : 'In this industry, referrals are exchanged without any payment of any kind. Instead, members earn credits and recognition, which have no cash value.';

        return implode("\n\n", [
            'Welcome to '.$name.'. In the next two minutes we will cover the rules every member follows, so that clients are looked after and every referral is easy to trust.',
            'First, consent. Only refer a client who has agreed to be referred. Tell them who you are introducing them to and why. Their contact details stay private until the receiving team accepts the referral.',
            'Second, speed. When a referral arrives, accept or decline it within '.$rule('accept_hours', 24).' hours. A quick no is better than a slow yes, because it lets the referring team find someone else. After you accept, contact the client within '.$rule('first_contact_hours', 24).' hours.',
            'Third, updates. Keep the referring team informed at least every '.$rule('update_days', 7).' days. They lent you their client’s trust, and the timeline shows them how it is going without chasing you.',
            'Fourth, the money. '.$money,
            'Finally, closing. When the work is done, or the client decides not to go ahead, mark the referral won or lost. The referring team confirms the outcome. Your trust score reflects how quickly you respond, how often you update, and how accurately you report outcomes.',
            'That is it. Complete the short quiz after this video, and you are ready to send and receive referrals in '.$name.'.',
        ]);
    }

    private function authorise(User $user, stdClass $s): void
    {
        $group = $s->group_id ? Group::query()->find((int) $s->group_id) : null;
        abort_unless($this->canManage($user, Industry::query()->findOrFail((int) $s->industry_id), $group), 403);
    }
}
