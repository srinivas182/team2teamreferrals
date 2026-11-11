<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Intake;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Ai\AiGateway;
use App\Domains\Referrals\Referral;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns intake text (a forwarded email, a voice transcript, text read from a photo) into a referral draft.
 * Phone numbers and emails are found by code in the source and never sent to the AI; the AI reads the masked text
 * for the name, what the client needs, timing, city and network, and must answer in a fixed format. A name is used
 * only if it appears in the source. Anything missing or uncertain is marked for review. Without AI, the draft keeps
 * the original text so nothing is lost.
 */
final class Extractor
{
    public const EXPIRES_DAYS = 14;

    public function __construct(private readonly AiGateway $gateway) {}

    public function draft(User $user, int $intakeId, string $text, int $credits): string
    {
        $team = Team::of($user) ?? Team::forLeader($user);
        $text = mb_substr(trim($text), 0, 12000);
        $phones = self::phones($text);
        $emails = array_values(array_filter(self::emails($text), fn ($e) => strtolower($e) !== strtolower($user->email)));
        $groups = Group::query()->with('industry')->whereIn('id', DB::table('seats')->where('primary_team_id', $team->id)->pluck('group_id'))->get();
        $review = [];
        $fields = ['client_name' => null, 'notes' => null, 'city' => null, 'network' => null];
        $ai = $this->gateway->run($team, $user, 'intake_extract', 'intake_extract', [
            'text' => Referral::maskContacts($text),
            'networks' => $groups->map(fn (Group $g) => $g->name.' ('.$g->industry->name.')')->implode('; ') ?: 'none',
        ], $credits, 400);
        if ($ai !== null) {
            $json = json_decode((string) preg_replace('/^```(json)?|```$/m', '', $ai['text']), true);
            if (is_array($json)) {
                foreach ($fields as $k => $_) {
                    $fields[$k] = is_string($json[$k] ?? null) && trim($json[$k]) !== '' ? trim(mb_substr($json[$k], 0, $k === 'notes' ? 1500 : 120)) : null;
                }
            }
        }
        // A name the source doesn't contain is never used.
        if ($fields['client_name'] !== null && ! str_contains(mb_strtolower($text), mb_strtolower($fields['client_name']))) {
            $fields['client_name'] = null;
        }
        $city = $fields['city'] !== null ? self::city($fields['city']) : null;
        $group = $groups->count() === 1 ? $groups->first() : ($fields['network'] !== null ? $groups->first(fn (Group $g) => str_contains(mb_strtolower($fields['network']), mb_strtolower($g->name)) || str_contains(mb_strtolower($fields['network']), mb_strtolower($g->industry->name))) : null);
        foreach (['client_name' => $fields['client_name'], 'client_phone' => $phones[0] ?? null, 'city' => $city, 'group' => $group] as $k => $v) {
            if ($v === null) {
                $review[$k] = 'missing';
            }
        }
        if (count($phones) > 1) {
            $review['client_phone'] = 'several';
        }
        if (count($emails) > 1) {
            $review['client_email'] = 'several';
        }
        $ulid = (string) Str::ulid();
        DB::table('referral_drafts')->insert(['ulid' => $ulid, 'user_id' => $user->id, 'team_id' => $team->id, 'intake_id' => $intakeId, 'group_id' => $group?->id, 'city_id' => $city,
            'client_name' => $fields['client_name'], 'client_phone' => $phones[0] ?? null, 'client_email' => $emails[0] ?? null,
            'notes' => $fields['notes'] ?? ($ai === null ? mb_substr($text, 0, 2000) : null), 'review' => json_encode($review), 'ai_used' => $ai !== null,
            'expires_at' => now()->addDays(self::EXPIRES_DAYS), 'created_at' => now(), 'updated_at' => now()]);

        return $ulid;
    }

    /** @return list<string> phone numbers as written in the source, in order */
    public static function phones(string $text): array
    {
        preg_match_all('/(?<![\d+])(\+?1[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)/', $text, $m);

        return array_values(array_unique(array_map('trim', $m[0])));
    }

    /** @return list<string> */
    public static function emails(string $text): array
    {
        preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m);

        return array_values(array_unique(array_map('strtolower', $m[0])));
    }

    /** The best matching city for "Houston" or "Houston, TX" (largest population first). */
    public static function city(string $name): ?int
    {
        [$city, $state] = array_pad(array_map('trim', explode(',', $name, 2)), 2, null);
        $id = DB::table('cities')->join('states', 'states.id', '=', 'cities.state_id')->where('cities.is_enabled', true)
            ->whereRaw('lower(cities.name) = ?', [mb_strtolower((string) $city)])->when($state, fn ($q, $s) => $q->whereRaw('upper(states.code) = ?', [strtoupper(substr($s, 0, 2))]))
            ->orderByDesc('cities.population')->value('cities.id');

        return $id !== null ? (int) $id : null;
    }
}
