<?php

declare(strict_types=1);

namespace App\Domains\Support\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Partners\Partner;
use App\Domains\Support\Ally\Ally;
use App\Domains\Support\Ally\AllyContext;
use App\Domains\Support\Ally\AllySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/** The Ally chat window: the conversation so far, asking, thumbs up or down, and passing to a person. */
final class AllyController
{
    private const SESSION = 'ally_conversation';

    public function state(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        if (! AllySettings::on($ctx->setting())) {
            return response()->json(['enabled' => false]);
        }
        $c = $this->conversation($request, $ctx, false);

        return response()->json([
            'enabled' => true, 'audience' => $ctx->audience, 'signedIn' => $ctx->user !== null || $ctx->partner !== null,
            'messages' => $c !== null ? DB::table('ally_messages')->where('conversation_id', $c->id)->orderBy('id')->limit(60)->get()->map(fn ($m) => $this->message($m))->values() : [],
            'ticket' => $c?->ticket_id !== null ? DB::table('support_tickets')->where('id', $c->ticket_id)->value('number') : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function ask(Request $request): JsonResponse
    {
        $d = $request->validate(['q' => ['required', 'string', 'max:1000'], 'page' => ['nullable', 'string', 'max:300']]);
        $ctx = $this->context($request);
        abort_unless(AllySettings::on($ctx->setting()), 404);
        if (! Ally::allowed($ctx, hash('sha256', $request->ip().'|'.$request->userAgent()))) {
            return response()->json(['message' => ['id' => null, 'role' => 'ally', 'body' => 'You’ve asked a lot of questions in a short time. Please try again a little later, or choose “Talk to a person”.',
                'sources' => [], 'actions' => [], 'answered' => false, 'helpful' => null], 'offerPerson' => true], 429);
        }
        $c = $this->conversation($request, $ctx, true, $d['page'] ?? null);
        $r = Ally::ask($c, $ctx, (string) $d['q']);

        return response()->json(['message' => ['id' => $r['id'], 'role' => 'ally', 'body' => $r['answer'], 'sources' => $r['sources'], 'actions' => $r['actions'], 'answered' => $r['answered'], 'helpful' => null],
            'offerPerson' => $r['offerPerson']]);
    }

    public function feedback(Request $request): JsonResponse
    {
        $d = $request->validate(['id' => ['required', 'integer'], 'helpful' => ['required', 'boolean']]);
        $c = $this->conversation($request, $this->context($request), false);
        abort_if($c === null, 404);
        DB::table('ally_messages')->where(['id' => (int) $d['id'], 'conversation_id' => $c->id, 'role' => 'ally'])->update(['helpful' => (bool) $d['helpful']]);

        return response()->json(['ok' => true]);
    }

    /** "Talk to a person": a ticket with the conversation. Visitors give a name and email (a contact request). */
    public function handOff(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        $known = $ctx->user !== null || $ctx->partner !== null;
        $d = $request->validate(['name' => [$known ? 'nullable' : 'required', 'string', 'max:120'], 'email' => [$known ? 'nullable' : 'required', 'email:rfc', 'max:190'], 'note' => ['nullable', 'string', 'max:2000']]);
        $c = $this->conversation($request, $ctx, false);
        abort_if($c === null || ! DB::table('ally_messages')->where('conversation_id', $c->id)->exists(), 422, 'Ask Ally something first, or contact support directly.');
        $number = Ally::handOff($c, $ctx, [
            'name' => $ctx->user->name ?? $ctx->partner->contact_name ?? (string) $d['name'],
            'email' => $ctx->user->email ?? $ctx->partner->email ?? (string) $d['email'], 'note' => $d['note'] ?? null,
        ]);

        return response()->json(['ticket' => $number, 'href' => $ctx->user !== null ? '/app/support' : null]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->session()->forget(self::SESSION);

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function message(stdClass $m): array
    {
        return ['id' => (int) $m->id, 'role' => $m->role, 'body' => $m->body, 'sources' => json_decode((string) ($m->sources ?? '[]'), true) ?: [],
            'actions' => json_decode((string) ($m->actions ?? '[]'), true) ?: [], 'answered' => $m->answered === null ? null : (bool) $m->answered, 'helpful' => $m->helpful === null ? null : (bool) $m->helpful];
    }

    /** This session's conversation (made on the first question); a different person signing in starts afresh. */
    private function conversation(Request $request, AllyContext $ctx, bool $create, ?string $page = null): ?stdClass
    {
        $ulid = $request->session()->get(self::SESSION);
        $c = is_string($ulid) ? DB::table('ally_conversations')->where('ulid', $ulid)->first() : null;
        if ($c !== null && ($c->user_id !== $ctx->user?->id || $c->partner_id !== $ctx->partner?->id)) {
            $c = null;
        }
        if ($c === null && $create) {
            $ulid = strtolower((string) Str::ulid());
            DB::table('ally_conversations')->insert(['ulid' => $ulid, 'user_id' => $ctx->user?->id, 'partner_id' => $ctx->partner?->id, 'audience' => $ctx->audience,
                'visitor_hash' => $ctx->user === null ? hash('sha256', $request->session()->getId()) : null, 'page' => $page !== null ? mb_substr((string) parse_url($page, PHP_URL_PATH), 0, 300) : null,
                'created_at' => now(), 'updated_at' => now()]);
            $request->session()->put(self::SESSION, $ulid);
            $c = DB::table('ally_conversations')->where('ulid', $ulid)->first();
        }

        return $c;
    }

    private function context(Request $request): AllyContext
    {
        $u = $request->user();
        $email = $request->session()->get('partner_portal');
        $partner = ! $u instanceof User && is_string($email) ? Partner::query()->where('email', $email)->orderByDesc('id')->first() : null;

        return AllyContext::for($u instanceof User ? $u : null, $partner);
    }
}
