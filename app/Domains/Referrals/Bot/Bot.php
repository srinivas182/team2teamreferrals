<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Bot;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Intake\Extractor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use stdClass;

/**
 * A member sending a referral by WhatsApp or SMS. The first detailed message becomes a draft (contacts verified against
 * what they wrote); the bot then asks one question at a time for anything still missing, and finally sends a one-tap
 * confirmation link. "discard" stops at any point; a session ends after 30 quiet minutes; each phone is throttled.
 */
final class Bot
{
    public const TIMEOUT_MINUTES = 30;

    public const THROTTLE = 20; // messages per 10 minutes

    public const LINK_MINUTES = 30;

    public function __construct(private readonly Extractor $extractor, private readonly IndustryTemplates $templates) {}

    /** @return string the reply to send back ('' for none) */
    public function handle(User $user, string $channel, string $phone, string $body): string
    {
        $body = trim($body);
        $since = now()->subMinutes(10);
        $recent = DB::table('bot_messages')->join('bot_sessions', 'bot_sessions.id', '=', 'bot_messages.session_id')
            ->where(['bot_sessions.user_id' => $user->id, 'bot_messages.direction' => 'in'])->where('bot_messages.created_at', '>=', $since)->count();
        if ($recent >= self::THROTTLE) {
            return '';
        }
        $session = DB::table('bot_sessions')->where('user_id', $user->id)->whereIn('state', ['collecting', 'confirming'])
            ->where('last_inbound_at', '>=', now()->subMinutes(self::TIMEOUT_MINUTES))->latest('id')->first();
        if ($session === null) {
            DB::table('bot_sessions')->where('user_id', $user->id)->whereIn('state', ['collecting', 'confirming'])->update(['state' => 'cancelled', 'updated_at' => now()]);
            $id = DB::table('bot_sessions')->insertGetId(['user_id' => $user->id, 'channel' => $channel, 'phone' => $phone, 'state' => 'collecting', 'last_inbound_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $session = self::row('bot_sessions', $id);
        }
        DB::table('bot_sessions')->where('id', $session->id)->update(['last_inbound_at' => now(), 'updated_at' => now()]);
        $this->log((int) $session->id, 'in', $body);
        $reply = $this->reply($user, $session, $channel, $body);
        $this->log((int) $session->id, 'out', $reply);

        return $reply;
    }

    private function reply(User $user, stdClass $session, string $channel, string $body): string
    {
        // Not "cancel": that is a carrier opt-out keyword, handled before the bot.
        if (in_array(strtolower($body), ['discard', 'start over'], true)) {
            if ($session->draft_id) {
                DB::table('referral_drafts')->where(['id' => $session->draft_id, 'status' => 'pending'])->update(['status' => 'discarded', 'updated_at' => now()]);
            }
            DB::table('bot_sessions')->where('id', $session->id)->update(['state' => 'cancelled']);

            return 'Cancelled. Send new referral details any time.';
        }
        if ($session->draft_id === null) {
            if (mb_strlen($body) < 15) {
                return 'Hi '.$user->first_name.'! Send me the referral in one message: who the client is, what they need, where and when, and their phone or email. Reply "discard" to stop.';
            }
            $intake = DB::table('intake_messages')->insertGetId(['user_id' => $user->id, 'team_id' => Team::of($user)?->id, 'channel' => $channel, 'body' => $body, 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]);
            $ulid = $this->extractor->draft($user, $intake, $body, 1);
            $draftId = (int) DB::table('referral_drafts')->where('ulid', $ulid)->value('id');
            DB::table('intake_messages')->where('id', $intake)->update(['status' => 'drafted']);
            DB::table('bot_sessions')->where('id', $session->id)->update(['draft_id' => $draftId]);
            $session->draft_id = $draftId;
        } elseif ($session->asking !== null) {
            $problem = $this->answer($user, (int) $session->draft_id, (string) $session->asking, $body);
            if ($problem !== null) {
                return $problem;
            }
        }

        return $this->next($user, $session);
    }

    /** The next missing detail as a question, or the one-tap link when nothing is missing. */
    private function next(User $user, stdClass $session): string
    {
        $d = self::row('referral_drafts', $session->draft_id);
        $groups = $this->groups($user);
        $ask = function (string $key, string $question) use ($session): string {
            DB::table('bot_sessions')->where('id', $session->id)->update(['asking' => $key, 'state' => 'collecting']);

            return $question;
        };
        if ($d->group_id === null) {
            if ($groups->isEmpty()) {
                return 'You need a seat in a network before sending referrals. Open the app to apply.';
            }

            return $ask('group', "Which network is this for?\n".$groups->values()->map(fn (Group $g, $i) => ($i + 1).') '.$g->name)->implode("\n"));
        }
        if ($d->client_name === null) {
            return $ask('client_name', 'What’s the client’s full name?');
        }
        if ($d->client_phone === null && $d->client_email === null) {
            return $ask('contact', 'What’s their phone number or email?');
        }
        if ($d->city_id === null) {
            return $ask('city', 'Which city do they need help in? (for example: Houston, TX)');
        }
        $answers = (array) json_decode((string) ($d->answers ?? '[]'), true);
        foreach ($this->form((int) $d->group_id) as $f) {
            if (($f['required'] ?? false) && ! array_key_exists($f['key'], $answers)) {
                $opts = $f['type'] === 'choice' ? "\n".collect($f['options'] ?? [])->values()->map(fn ($o, $i) => ($i + 1).') '.$o)->implode("\n") : ($f['type'] === 'boolean' ? ' (yes or no)' : '');

                return $ask('answer:'.$f['key'], $f['label'].'?'.$opts);
            }
        }
        DB::table('bot_sessions')->where('id', $session->id)->update(['asking' => null, 'state' => 'confirming']);
        $link = URL::temporarySignedRoute('bot.confirm', now()->addMinutes(self::LINK_MINUTES), ['draft' => $d->ulid]);

        return 'Ready to send your referral for '.self::short((string) $d->client_name).". Tap to confirm (valid 30 minutes):\n".$link;
    }

    /** @return string|null a re-ask when the answer doesn't fit, null when it was saved */
    private function answer(User $user, int $draftId, string $asking, string $body): ?string
    {
        $update = [];
        if ($asking === 'group') {
            $groups = $this->groups($user)->values();
            $g = ctype_digit($body) ? $groups->get((int) $body - 1) : $groups->first(fn (Group $x) => str_contains(mb_strtolower($x->name), mb_strtolower($body)));
            if ($g === null) {
                return 'Reply with the number of the network, please.';
            }
            $update['group_id'] = $g->id;
        } elseif ($asking === 'client_name') {
            if (mb_strlen($body) < 2) {
                return 'Please send the client’s name.';
            }
            $update['client_name'] = mb_substr($body, 0, 120);
        } elseif ($asking === 'contact') {
            $phone = Extractor::phones($body)[0] ?? null;
            $email = Extractor::emails($body)[0] ?? null;
            if ($phone === null && $email === null) {
                return 'That doesn’t look like a phone number or email. Please try again.';
            }
            $update += array_filter(['client_phone' => $phone, 'client_email' => $email]);
        } elseif ($asking === 'city') {
            $city = Extractor::city($body);
            if ($city === null) {
                return 'I couldn’t find that city. Try “City, ST”, for example Houston, TX.';
            }
            $update['city_id'] = $city;
        } elseif (str_starts_with($asking, 'answer:')) {
            $key = substr($asking, 7);
            $d = self::row('referral_drafts', $draftId);
            $f = collect($this->form((int) $d->group_id))->firstWhere('key', $key);
            $value = match ($f['type'] ?? 'text') {
                'choice' => ctype_digit($body) ? (($f['options'] ?? [])[(int) $body - 1] ?? null) : collect($f['options'] ?? [])->first(fn ($o) => mb_strtolower($o) === mb_strtolower($body)),
                'boolean' => in_array(strtolower($body), ['yes', 'y'], true) ? true : (in_array(strtolower($body), ['no', 'n'], true) ? false : null),
                'number' => is_numeric(str_replace([',', '$'], '', $body)) ? str_replace([',', '$'], '', $body) : null,
                'date' => ($t = strtotime($body)) !== false ? date('Y-m-d', $t) : null,
                default => mb_substr($body, 0, 500),
            };
            if ($value === null) {
                return 'Sorry, I didn’t understand. '.$f['label'].'?';
            }
            $answers = (array) json_decode((string) ($d->answers ?? '[]'), true);
            $update['answers'] = json_encode([...$answers, $key => $value]);
        }
        DB::table('referral_drafts')->where('id', $draftId)->update([...$update, 'updated_at' => now()]);

        return null;
    }

    /** @return \Illuminate\Support\Collection<int, Group> */
    private function groups(User $user): \Illuminate\Support\Collection
    {
        $team = Team::of($user);

        return Group::query()->whereIn('id', DB::table('seats')->where('primary_team_id', $team !== null ? $team->id : 0)->pluck('group_id'))->orderBy('name')->get();
    }

    /** @return list<array{key: string, label: string, type: string, required?: bool, options?: list<string>}> the network's form, without the client fields asked separately */
    private function form(int $groupId): array
    {
        $g = Group::query()->with('industry')->findOrFail($groupId);
        /** @var list<array{key: string, label: string, type: string, required?: bool, options?: list<string>}> $fields */
        $fields = $this->templates->resolve('form', $g->industry, $g->id)['body'];

        return array_values(array_filter($fields, fn ($f) => ! in_array($f['key'], ['client_name', 'client_phone', 'client_email'], true)));
    }

    private function log(int $session, string $direction, string $body): void
    {
        DB::table('bot_messages')->insert(['session_id' => $session, 'direction' => $direction, 'body' => $body, 'created_at' => CarbonImmutable::now()]);
    }

    private static function row(string $table, int|string $id): stdClass
    {
        $r = DB::table($table)->where('id', (int) $id)->first();
        abort_if($r === null, 404);

        return $r;
    }

    /** Status replies and chat never repeat full client names: "Tom Brennan" becomes "Tom B.". */
    public static function short(string $name): string
    {
        $p = preg_split('/\s+/', trim($name)) ?: [];

        return count($p) > 1 ? $p[0].' '.mb_substr((string) end($p), 0, 1).'.' : trim($name);
    }
}
