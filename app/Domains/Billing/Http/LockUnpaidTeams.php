<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http;

use App\Domains\Billing\TeamLock;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a locked (unpaid) team on the Renew page. Still open to them: Renew and Billing (to pay), their account and
 * notification settings, signing out, and the clients they had already accepted (and the pipeline listing them).
 */
final class LockUnpaidTeams
{
    private const OPEN = ['app/renew', 'app/renew/*', 'app/billing', 'app/billing/*', 'app/settings', 'app/settings/*', 'app/notifications', 'app/notifications/*', 'app/pipeline'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->isStaff() || ! $request->is('app', 'app/*') || $request->is(...self::OPEN)) {
            return $next($request);
        }
        if (! TeamLock::lockedFor($user)) {
            return $next($request);
        }
        $team = Team::of($user);
        if ($team === null || $this->acceptedClient($request, $team)) {
            return $next($request);
        }

        return $request->expectsJson() ? response()->json(['message' => 'Your team’s seats are inactive until you renew.'], 402) : redirect()->to('/app/renew');
    }

    /** A referral the team had already accepted: it can keep working it (/app/referrals/{id} and its actions). */
    private function acceptedClient(Request $request, Team $team): bool
    {
        if (! preg_match('#^app/referrals/([0-9A-Za-z]{26})(/|$)#', $request->path(), $m)) {
            return false;
        }
        $ref = Referral::query()->where('ulid', $m[1])->first(['id', 'status', 'receiver_team_id']);

        return $ref !== null && $ref->status === 'accepted' && $ref->receiver_team_id === $team->id;
    }
}
