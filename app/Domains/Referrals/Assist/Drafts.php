<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Assist;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Ai\AiGateway;
use App\Domains\Referrals\Referral;

/**
 * AI drafts of follow-up messages, to the referring/receiving team or (for the receiving team, after accepting) the
 * client. One credit each. Never sent automatically: the member edits and sends. The output is filtered: no fee talk
 * in Tier B, and no promised outcomes.
 */
final class Drafts
{
    public const TONES = ['friendly', 'professional', 'brief'];

    public function __construct(private readonly AiGateway $gateway) {}

    /** @return array{text: string, usageId: int}|null */
    public function draft(Referral $ref, User $user, string $to, string $tone): ?array
    {
        $team = Team::of($user);
        abort_unless($team !== null && in_array($team->id, [$ref->sender_team_id, $ref->receiver_team_id], true), 403);
        abort_unless(in_array($tone, self::TONES, true) && in_array($to, ['partner', 'client'], true), 422);
        // Only the receiving team, after accepting, may write to the client.
        abort_if($to === 'client' && ($team->id !== $ref->receiver_team_id || $ref->status !== 'accepted'), 403);
        $ref->loadMissing(['group.industry', 'sender', 'receiver', 'seat']);
        $receiving = $team->id === $ref->receiver_team_id;
        $partner = $receiving ? $ref->sender : $ref->receiver;
        $restricted = $ref->group->industry->tier !== Tier::Open;
        $actions = $receiving ? array_column(NextActions::for($ref, $team), 'title') : [];
        $facts = implode("\n", array_filter([
            'From: '.$team->name.($receiving ? ' (the team that received the referral)' : ' (the team that sent the referral)'),
            'To: '.($to === 'client' ? 'the client, '.$ref->shortClientName() : ($partner !== null ? $partner->name : 'the other team')),
            'Industry: '.$ref->group->industry->name.' · City: '.$ref->seat->territory_name,
            'Stage: '.str_replace('_', ' ', (string) ($ref->stage ?? $ref->status)),
            'Timing: '.($ref->temperature ?? 'not given'),
            $actions !== [] ? 'What needs doing: '.implode('; ', $actions) : null,
        ]));
        $r = $this->gateway->run($team, $user, 'follow_up_draft', 'follow_up_draft', ['facts' => $facts, 'tone' => $tone,
            'fee_rule' => $restricted ? 'Never mention referral fees or payments: this industry does not allow them.' : 'Don’t mention fees unless asked.'], 1, 350);
        if ($r === null) {
            return null;
        }

        return ['text' => self::clean($r['text'], $ref, $restricted), 'usageId' => $r['usage_id']];
    }

    /** No contacts, no full client name, no promised outcomes, and no fee talk in Tier B. */
    public static function clean(string $text, Referral $ref, bool $restricted): string
    {
        $text = Referral::maskContacts(str_ireplace(trim($ref->client_name), $ref->shortClientName(), $text));
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($text)) ?: [];
        $sentences = array_filter($sentences, fn ($s) => ! preg_match('/\b(guarantee[ds]?|promise[ds]?|100% (sure|certain)|will definitely)\b/i', $s)
            && ! ($restricted && preg_match('/\b(referral fee|fee|commission split|kickback|payment for)\b/i', $s)));

        return trim(mb_substr(implode(' ', $sentences), 0, 1500));
    }
}
