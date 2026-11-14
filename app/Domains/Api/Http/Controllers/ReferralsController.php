<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Controllers;

use App\Domains\Api\Resources;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** /api/v1/referrals: the same actions as the app, with the same rules (consent, routing, deadlines, privacy). */
final class ReferralsController extends Controller
{
    public function __construct(private readonly Referrals $referrals, private readonly Pipeline $pipeline) {}

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['direction' => ['nullable', 'in:sent,received'], 'status' => ['nullable', 'in:sent,accepted,declined,expired,withdrawn'],
            'updated_since' => ['nullable', 'date'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:500']]);
        $team = $this->team($request);
        $q = Referral::query()->with(['group.industry', 'seat', 'sender', 'receiver'])
            ->when(($d['direction'] ?? null) === 'sent', fn ($q) => $q->where('sender_team_id', $team->id))
            ->when(($d['direction'] ?? null) === 'received', fn ($q) => $q->where('receiver_team_id', $team->id))
            ->when(! isset($d['direction']), fn ($q) => $q->where(fn ($q) => $q->where('sender_team_id', $team->id)->orWhere('receiver_team_id', $team->id)))
            ->when(isset($d['status']), fn ($q) => $q->where('status', $d['status']))
            ->when(isset($d['updated_since']), fn ($q) => $q->where('updated_at', '>=', now()->parse((string) $d['updated_since'])))
            ->orderByDesc('id');
        $page = $q->cursorPaginate($this->limit($request));

        return response()->json(Resources::page($page, array_values(array_map(fn (Referral $r) => Resources::referral($r, $team), $page->items()))));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(Resources::referral($this->referral($request, $id), $this->team($request)));
    }

    /** Send a referral. The client must have agreed to be contacted ("consent": true), as in the app. */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'group' => ['required', 'string', 'exists:groups,slug'], 'city_id' => ['required', 'integer', 'exists:cities,id'], 'answers' => ['required', 'array'],
            'consent' => ['accepted'], 'notes' => ['nullable', 'string', 'max:2000'], 'text_client' => ['boolean'], 'allow_duplicate' => ['boolean'],
        ], ['consent.accepted' => 'Your client must agree to be contacted before you send a referral: send "consent": true once they have.']);
        $team = $this->team($request);
        $group = Group::query()->where('slug', $d['group'])->firstOrFail();
        // A test token works only in the sandbox network, and a live token never does.
        abort_if($group->is_sandbox !== $this->token($request)->isTest(), 422, $group->is_sandbox ? 'That group is the API sandbox: use a test token.' : 'Test tokens work in the sandbox network only: see GET /api/v1/groups.');
        $r = $this->referrals->send($this->user($request), $group, City::query()->findOrFail((int) $d['city_id']), (array) $d['answers'], true, $d['notes'] ?? null,
            (bool) ($d['allow_duplicate'] ?? false), (bool) ($d['text_client'] ?? false));
        if ($r['duplicate']) {
            throw new ConflictHttpException('This client was referred in this group in the last 30 days. If it is a new referral, send it again with "allow_duplicate": true.');
        }

        return response()->json(Resources::referral($r['referral'], $team), 201, ['Location' => url('/api/v1/referrals/'.$r['referral']->ulid)]);
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        $ref = $this->referral($request, $id);
        $this->referrals->accept($ref, $this->user($request));

        return response()->json(Resources::referral($ref->fresh() ?? $ref, $this->team($request)));
    }

    public function decline(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tell the sender why, so they can help their client.']);
        $ref = $this->referral($request, $id);
        $this->referrals->decline($ref, $this->user($request), (string) $d['reason']);

        return response()->json(Resources::referral($ref->fresh() ?? $ref, $this->team($request)));
    }

    /** Move an accepted referral to a pipeline stage (won needs closing date and value; lost needs a reason). */
    public function stage(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['stage' => ['required', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:2000'], 'closing_date' => ['nullable', 'date'],
            'closing_value' => ['nullable', 'numeric', 'min:0'], 'earnings' => ['nullable', 'numeric', 'min:0'], 'lost_reason' => ['nullable', 'string', 'max:500']]);
        $ref = $this->referral($request, $id);
        $this->pipeline->move($ref, $this->user($request), (string) $d['stage'], $d['note'] ?? null, $d);

        return response()->json(Resources::referral($ref->fresh() ?? $ref, $this->team($request)));
    }

    public function note(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $ref = $this->referral($request, $id);
        $this->pipeline->note($ref, $this->user($request), (string) $d['note']);

        return response()->json(Resources::referral($ref->fresh() ?? $ref, $this->team($request)), 201);
    }

    /** The referral's timeline, oldest first. */
    public function events(Request $request, string $id): JsonResponse
    {
        $ref = $this->referral($request, $id);
        $hide = $ref->receiver_team_id === $this->team($request)->id && $ref->status !== 'accepted';
        $teams = \App\Domains\Network\Seats\Models\Team::query()->whereIn('id', DB::table('referral_events')->where('referral_id', $ref->id)->whereNotNull('team_id')->pluck('team_id'))->pluck('name', 'id');
        $events = DB::table('referral_events')->where('referral_id', $ref->id)->orderBy('id')->get()->map(function ($e) use ($teams, $hide) {
            $meta = (array) json_decode((string) $e->meta, true);
            if ($hide && isset($meta['note']) && is_string($meta['note'])) {
                $meta['note'] = Referral::maskContacts($meta['note']);
            }

            return ['type' => (string) $e->event, 'at' => now()->parse((string) $e->created_at)->toIso8601String(), 'team' => $e->team_id !== null ? ($teams[$e->team_id] ?? null) : null, 'details' => $meta];
        })->values()->all();

        return response()->json(['object' => 'list', 'data' => $events, 'has_more' => false, 'next_cursor' => null]);
    }
}
