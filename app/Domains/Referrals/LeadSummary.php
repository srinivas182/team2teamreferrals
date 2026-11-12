<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Ai\AiGateway;
use Illuminate\Support\Facades\DB;

/**
 * The receiving team's AI summary of a referral. Built only from this referral, which the receiving team can see;
 * the client's name and contact details are removed before anything is sent, and the output is checked again,
 * because before acceptance the receiver must not see them. Tier B summaries never mention fees. One credit, and
 * reused until the referral's details change.
 */
final class LeadSummary
{
    public function __construct(private readonly AiGateway $gateway, private readonly IndustryTemplates $templates) {}

    /** @return array{summary: string, usageId: ?int}|null */
    public function for(Referral $ref, User $viewer): ?array
    {
        if (! app(\App\Domains\Platform\Modules\Modules::class)->enabled('ai_summaries')) {
            return null;
        }
        $team = Team::of($viewer);
        if ($team === null || $team->id !== $ref->receiver_team_id) {
            return null; // only for the team deciding whether to accept
        }
        $ref->loadMissing(['group.industry', 'seat']);
        $input = $this->input($ref);
        $hash = hash('sha256', $input);
        $cached = DB::table('ai_summaries')->where('referral_id', $ref->id)->first();
        if ($cached !== null && $cached->input_hash === $hash) {
            return ['summary' => (string) $cached->summary, 'usageId' => $cached->usage_id !== null ? (int) $cached->usage_id : null];
        }
        $restricted = $ref->group->industry->tier !== Tier::Open;
        $r = $this->gateway->run($team, $viewer, 'lead_summary', 'lead_summary', ['referral' => $input,
            'fee_rule' => $restricted ? 'Never mention referral fees or payments: this industry does not allow them.' : 'You may mention the agreed referral fee only if it appears in the data.']);
        if ($r === null) {
            return null;
        }
        $summary = self::clean($r['text'], $ref, $restricted);
        DB::table('ai_summaries')->updateOrInsert(['referral_id' => $ref->id], ['input_hash' => $hash, 'summary' => $summary, 'usage_id' => $r['usage_id'], 'updated_at' => now(), 'created_at' => now()]);

        return ['summary' => $summary, 'usageId' => $r['usage_id']];
    }

    /** The output is checked, not just the instructions: no contacts, no full name, and no fee talk in Tier B. */
    public static function clean(string $text, Referral $ref, bool $restricted): string
    {
        $text = Referral::maskContacts($text);
        $short = $ref->shortClientName();
        $parts = preg_split('/\s+/', trim($ref->client_name)) ?: [];
        $text = str_ireplace(trim($ref->client_name), $short, $text);
        if (count($parts) > 1) {
            $text = (string) preg_replace('/\b'.preg_quote((string) end($parts), '/').'\b/iu', mb_substr((string) end($parts), 0, 1).'.', $text);
        }
        if ($restricted) {
            $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [];
            $text = implode(' ', array_filter($sentences, fn ($s) => ! preg_match('/\b(referral fee|fee|commission split|kickback|payment for)\b/i', $s)));
        }

        return trim(mb_substr($text, 0, 1200));
    }

    /** What the model sees: the referral's own facts, with the client reduced to a short name and contacts masked. */
    private function input(Referral $ref): string
    {
        $form = $this->templates->resolve('form', $ref->group->industry, $ref->group_id)['body'];
        $lines = ['Industry: '.$ref->group->industry->name, 'City: '.$ref->seat->territory_name, 'Client: '.$ref->shortClientName(), 'Timing: '.($ref->temperature ?? 'not given')];
        foreach ($form as $f) {
            if (in_array($f['key'], ['client_name', 'client_phone', 'client_email'], true)) {
                continue;
            }
            $v = data_get($ref->answers, $f['key']);
            if ($v !== null && $v !== '' && $v !== []) {
                $lines[] = $f['label'].': '.Referral::maskContacts(is_array($v) ? implode(', ', $v) : (is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v));
            }
        }
        if ($ref->notes) {
            $lines[] = 'Sender’s notes: '.Referral::maskContacts(str_ireplace(trim($ref->client_name), $ref->shortClientName(), (string) $ref->notes));
        }

        return implode("\n", $lines);
    }
}
