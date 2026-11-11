<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Assist\Drafts;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** One-tap use, dismiss or snooze of a suggestion, and AI message drafts (never sent automatically). */
final class AssistController
{
    public const DISMISS_REASONS = ['not_relevant', 'already_done', 'wrong_timing', 'other'];

    public function act(Request $request, Referral $referral, string $key, string $action): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(app(Referrals::class)->canView($referral, $user), 404);
        $team = Team::of($user);
        abort_if($team === null, 403);
        $reason = $action === 'dismiss' ? $request->validate(['reason' => ['nullable', 'in:'.implode(',', self::DISMISS_REASONS)]])['reason'] ?? null : null;
        DB::table('ai_suggestions')->insert(['team_id' => $team->id, 'user_id' => $user->id, 'referral_id' => $referral->id, 'kind' => (string) $request->input('kind', 'next_action'),
            'key' => $key, 'status' => match ($action) {
                'dismiss' => 'dismissed', 'snooze' => 'snoozed', default => 'used'
            }, 'reason' => $reason,
            'snoozed_until' => $action === 'snooze' ? now()->addDay() : null, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'suggestion-'.$action);
    }

    public function draft(Request $request, Referral $referral, Drafts $drafts): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(app(Referrals::class)->canView($referral, $user), 404);
        $d = $request->validate(['to' => ['required', 'in:partner,client'], 'tone' => ['required', 'in:'.implode(',', Drafts::TONES)]]);
        $r = $drafts->draft($referral, $user, (string) $d['to'], (string) $d['tone']);

        return back()->with($r === null ? ['status' => 'draft-unavailable'] : ['draft' => [...$r, 'to' => $d['to']]]);
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
