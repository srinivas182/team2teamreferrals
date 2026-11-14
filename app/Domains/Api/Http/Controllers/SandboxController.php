<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Controllers;

use App\Domains\Api\Resources;
use App\Domains\Api\Sandbox;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * Test helpers (test tokens only): make the sandbox's sample teams act, so every step of a referral can be tried —
 * receive one, or have the receiving sample team accept, decline, move or close one you sent.
 */
final class SandboxController extends Controller
{
    public function incoming(Request $request): JsonResponse
    {
        $this->testOnly($request);
        $d = $request->validate(['answers' => ['nullable', 'array']]);

        return response()->json(Resources::referral(Sandbox::incoming($this->team($request), (array) ($d['answers'] ?? [])), $this->team($request)), 201);
    }

    /** The sample team that received a referral you sent acts on it. */
    public function simulate(Request $request, string $id): JsonResponse
    {
        $this->testOnly($request);
        $d = $request->validate(['action' => ['required', 'in:accept,decline,stage'], 'reason' => ['nullable', 'string', 'max:500'], 'stage' => ['required_if:action,stage', 'nullable', 'string', 'max:40'],
            'closing_date' => ['nullable', 'date'], 'closing_value' => ['nullable', 'numeric', 'min:0'], 'earnings' => ['nullable', 'numeric', 'min:0'], 'lost_reason' => ['nullable', 'string', 'max:500']]);
        $ref = $this->referral($request, $id);
        abort_unless($ref->sender_team_id === $this->team($request)->id && $ref->receiver !== null, 422, 'Simulate works on referrals your team sent: the sample team that received it acts.');
        $actor = $ref->receiver->leader;
        match ($d['action']) {
            'accept' => app(Referrals::class)->accept($ref, $actor),
            'decline' => app(Referrals::class)->decline($ref, $actor, (string) ($d['reason'] ?? 'Sandbox: declined on request.')),
            'stage' => app(Pipeline::class)->move($ref, $actor, (string) $d['stage'], null, $d),
            default => throw new LogicException('Validated above.'),
        };

        return response()->json(Resources::referral($ref->fresh() ?? $ref, $this->team($request)));
    }

    private function testOnly(Request $request): void
    {
        abort_unless($this->token($request)->isTest(), 403, 'Sandbox helpers work with test tokens only.');
    }
}
