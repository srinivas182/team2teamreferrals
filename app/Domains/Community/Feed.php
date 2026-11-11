<?php

declare(strict_types=1);

namespace App\Domains\Community;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Referrals\Referral;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * A network's feed: posts, "Ask the network", owner announcements and win announcements, with comments and
 * reactions. Moderated from day one: client contact details are masked, a word filter applies, and three reports
 * hide an item until the group owner restores or removes it.
 */
final class Feed
{
    public const AUTO_HIDE_REPORTS = 3;

    public const DEFAULT_BLOCKED = 'fuck,shit,bitch,asshole,bastard,cunt,dick,slut,whore,nigger,faggot,retard';

    public function __construct(private readonly Notifier $notifier) {}

    public static function member(User $user, Group $group): bool
    {
        $team = Team::of($user);

        return $group->isOwnedBy($user) || $user->isStaff()
            || ($team !== null && DB::table('seats')->where(['group_id' => $group->id, 'primary_team_id' => $team->id])->exists());
    }

    /** Masks phone numbers and emails, and stars out blocked words (admins can change the list). */
    public static function clean(string $text): string
    {
        $text = Referral::maskContacts(trim($text));
        $words = array_filter(array_map('trim', explode(',', (string) app(Settings::class)->get('feed.blocked_words', self::DEFAULT_BLOCKED))));
        foreach ($words as $w) {
            $text = (string) preg_replace('/\b'.preg_quote($w, '/').'\b/iu', str_repeat('*', mb_strlen($w)), $text);
        }

        return $text;
    }

    public function post(Group $group, User $user, string $kind, string $body, ?int $cityId = null): int
    {
        abort_unless(self::member($user, $group), 403);
        abort_if($kind === 'announcement' && ! ($group->isOwnedBy($user) || $user->isStaff()), 403, 'Only the network owner can post announcements.');
        if ($kind === 'ask' && $cityId === null) {
            throw ValidationException::withMessages(['city_id' => 'Choose the city you need a pro in.']);
        }
        $id = DB::table('feed_posts')->insertGetId(['group_id' => $group->id, 'team_id' => Team::of($user)?->id, 'user_id' => $user->id, 'kind' => $kind, 'body' => self::clean($body),
            'city_id' => $kind === 'ask' ? $cityId : null, 'pinned_at' => $kind === 'announcement' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        if ($kind === 'announcement') {
            // Announcements are the one kind of post every member hears about straight away.
            foreach ($this->members($group, except: $user->id) as $m) {
                $this->notifier->send($m, 'network.announcement', ['body' => mb_substr(self::clean($body), 0, 300)], url('/app/feed/'.$group->slug));
            }
        }
        $this->mentions($group, $user, $body, $id);
        FeedChanged::dispatch($group->slug, $id);

        return $id;
    }

    public function comment(int $postId, User $user, string $body): void
    {
        $post = $this->visiblePost($postId);
        $group = Group::query()->findOrFail((int) $post->group_id);
        abort_unless(self::member($user, $group), 403);
        $team = Team::of($user);
        // On an Ask: a reply from the team holding that city's seat is an offer the asker can act on in one tap.
        $offer = $post->kind === 'ask' && $team !== null && DB::table('seats')->where(['group_id' => $group->id, 'territory_id' => $post->city_id, 'primary_team_id' => $team->id])->exists();
        DB::table('feed_comments')->insert(['post_id' => $postId, 'team_id' => $team?->id, 'user_id' => $user->id, 'body' => self::clean($body), 'offer' => $offer, 'created_at' => now(), 'updated_at' => now()]);
        // Replies notify the post's author instantly (not their own).
        if ((int) $post->user_id !== $user->id && ($author = User::query()->find((int) $post->user_id)) !== null) {
            $this->notifier->send($author, 'feed.reply', ['headline' => ($team !== null ? $team->name : $user->name).($offer ? ' covers that city and replied' : ' replied to your post'), 'body' => mb_substr(self::clean($body), 0, 200)], url('/app/feed/'.$group->slug.'#post-'.$postId));
        }
        $this->mentions($group, $user, $body, $postId);
        FeedChanged::dispatch($group->slug, $postId);
    }

    public function react(int $postId, User $user, string $kind): void
    {
        $post = $this->visiblePost($postId);
        abort_unless(self::member($user, Group::query()->findOrFail((int) $post->group_id)), 403);
        $existing = DB::table('feed_reactions')->where(['post_id' => $postId, 'user_id' => $user->id])->first();
        if ($existing !== null && $existing->kind === $kind) {
            DB::table('feed_reactions')->where('id', $existing->id)->delete(); // tap again to take it back

            return;
        }
        DB::table('feed_reactions')->updateOrInsert(['post_id' => $postId, 'user_id' => $user->id], ['kind' => $kind, 'created_at' => now()]);
    }

    public function pin(int $postId, User $user): void
    {
        $post = $this->visiblePost($postId);
        $group = Group::query()->findOrFail((int) $post->group_id);
        abort_unless($group->isOwnedBy($user) || $user->isStaff(), 403);
        DB::table('feed_posts')->where('id', $postId)->update(['pinned_at' => $post->pinned_at ? null : now()]);
    }

    /** A member reports a post or comment; the third report hides it for the owner to review. */
    public function report(string $target, int $id, User $user, ?string $reason): void
    {
        $table = $target === 'post' ? 'feed_posts' : 'feed_comments';
        $row = DB::table($table)->where('id', $id)->whereNull('removed_at')->first();
        abort_if($row === null, 404);
        $group = Group::query()->with('owners')->findOrFail((int) ($target === 'post' ? $row->group_id : DB::table('feed_posts')->where('id', $row->post_id)->value('group_id')));
        abort_unless(self::member($user, $group), 403);
        if (DB::table('feed_reports')->insertOrIgnore(['target' => $target, 'target_id' => $id, 'user_id' => $user->id, 'reason' => $reason, 'created_at' => now()]) !== 1) {
            return; // one report per person
        }
        $reports = (int) $row->reports + 1;
        DB::table($table)->where('id', $id)->update(['reports' => $reports, 'hidden_at' => $reports >= self::AUTO_HIDE_REPORTS ? ($row->hidden_at ?? now()) : $row->hidden_at]);
        if ($reports === self::AUTO_HIDE_REPORTS) {
            foreach ($group->owners as $owner) {
                $this->notifier->send($owner, 'feed.moderation', ['headline' => 'A '.$target.' was hidden after '.self::AUTO_HIDE_REPORTS.' reports', 'body' => 'Review it in the moderation queue: restore it or remove it.'], url('/app/group/'.$group->slug.'/moderation'));
            }
        }
    }

    /** Owner decision on a reported item: restore (clears reports) or remove. */
    public function moderate(string $target, int $id, User $user, bool $restore): void
    {
        $table = $target === 'post' ? 'feed_posts' : 'feed_comments';
        $row = DB::table($table)->where('id', $id)->first();
        abort_if($row === null, 404);
        $group = Group::query()->findOrFail((int) ($target === 'post' ? $row->group_id : DB::table('feed_posts')->where('id', $row->post_id)->value('group_id')));
        abort_unless($group->isOwnedBy($user) || $user->isStaff(), 403);
        DB::table($table)->where('id', $id)->update($restore ? ['hidden_at' => null, 'reports' => 0] : ['removed_at' => now()]);
        if ($restore) {
            DB::table('feed_reports')->where(['target' => $target, 'target_id' => $id])->delete();
        }
        activity('group')->causedBy($user)->performedOn($group)->event('feed-'.($restore ? 'restored' : 'removed'))->withProperties(['target' => $target, 'id' => $id])->log(($restore ? 'Restored' : 'Removed').' a '.$target);
    }

    /** One team agrees to share a closed win; when both have, it's posted (no client details, fixed wording). */
    public function agreeToShareWin(Referral $ref, User $user): ?int
    {
        $team = Team::of($user);
        abort_unless($team !== null && in_array($team->id, [$ref->sender_team_id, $ref->receiver_team_id], true) && in_array($ref->close_status, ['confirmed', 'auto_confirmed'], true), 403);
        DB::table('win_shares')->insertOrIgnore(['referral_id' => $ref->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('win_shares')->where('referral_id', $ref->id)->update([$team->id === $ref->sender_team_id ? 'sender_ok_at' : 'receiver_ok_at' => now(), 'updated_at' => now()]);
        $share = DB::table('win_shares')->where('referral_id', $ref->id)->first();
        abort_if($share === null, 500);
        if ($share->post_id !== null || $share->sender_ok_at === null || $share->receiver_ok_at === null) {
            if ($share->post_id === null) {
                $ref->loadMissing(['sender.leader', 'receiver.leader']);
                $other = $team->id === $ref->sender_team_id ? $ref->receiver : $ref->sender;
                if ($other !== null) {
                    $this->notifier->send($other->leader, 'feed.reply', ['headline' => "{$team->name} would like to share your win in the network", 'body' => 'No client details are shared. Agree on the referral page to post it.'], url('/app/referrals/'.$ref->ulid));
                }
            }

            return $share->post_id !== null ? (int) $share->post_id : null;
        }
        $ref->loadMissing(['sender', 'receiver', 'group']);
        $id = DB::table('feed_posts')->insertGetId(['group_id' => $ref->group_id, 'team_id' => $ref->receiver_team_id, 'user_id' => $user->id, 'kind' => 'win', 'referral_id' => $ref->id,
            'body' => "{$ref->receiver?->name} closed a deal thanks to a referral from {$ref->sender->name}. Thank you!", 'created_at' => now(), 'updated_at' => now()]);
        DB::table('win_shares')->where('referral_id', $ref->id)->update(['post_id' => $id]);
        FeedChanged::dispatch($ref->group->slug, $id);

        return $id;
    }

    /** @return list<User> */
    private function members(Group $group, int $except): array
    {
        $teamIds = DB::table('seats')->where('group_id', $group->id)->whereNotNull('primary_team_id')->pluck('primary_team_id');

        return array_values(User::query()->whereIn('id', Team::query()->whereIn('id', $teamIds)->pluck('leader_id'))->where('id', '!=', $except)->get()->all());
    }

    /** "@team-web-name" in a post or comment notifies that team instantly. */
    private function mentions(Group $group, User $by, string $body, int $postId): void
    {
        preg_match_all('/@([a-z0-9][a-z0-9-]{1,79})/', strtolower($body), $m);
        foreach (array_unique($m[1]) as $slug) {
            $team = Team::query()->with('leader')->where('slug', $slug)->first();
            if ($team !== null && $team->leader_id !== $by->id && self::member($team->leader, $group)) {
                $this->notifier->send($team->leader, 'feed.mention', ['headline' => (($t = Team::of($by)) !== null ? $t->name : $by->name).' mentioned you in '.$group->name, 'body' => mb_substr(self::clean($body), 0, 200)], url('/app/feed/'.$group->slug.'#post-'.$postId));
            }
        }
    }

    private function visiblePost(int $id): stdClass
    {
        $post = DB::table('feed_posts')->where('id', $id)->whereNull('removed_at')->whereNull('hidden_at')->first();
        abort_if($post === null, 404);

        return $post;
    }
}
