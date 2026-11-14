<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Controllers;

use App\Domains\Api\Resources;
use App\Domains\Api\Webhooks\WebhookDelivery;
use App\Domains\Api\Webhooks\WebhookEndpoint;
use App\Domains\Api\Webhooks\WebhookEvents;
use App\Domains\Api\Webhooks\Webhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook endpoints by API (scope webhooks:manage), e.g. for tools that subscribe and unsubscribe themselves. A live
 * token manages live endpoints and a test token manages test (sandbox) endpoints; both belong to the real team.
 */
final class WebhooksController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = $this->query($request)->orderBy('id')->get()->map(fn (WebhookEndpoint $e) => Resources::webhookEndpoint($e))->values()->all();

        return response()->json(['object' => 'list', 'data' => $items, 'has_more' => false, 'next_cursor' => null]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request, creating: true);
        $token = $this->token($request);
        $r = Webhooks::create($token->team, $token->user, (string) $d['url'], $token->mode, array_values((array) ($d['events'] ?? ['*'])), $d['description'] ?? null);

        // The signing secret is only ever returned here and when it's rotated.
        return response()->json([...Resources::webhookEndpoint($r['endpoint']), 'secret' => $r['secret']], 201, ['Location' => url('/api/v1/webhooks/'.$r['endpoint']->ulid)]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(Resources::webhookEndpoint($this->endpoint($request, $id)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $d = $this->validated($request, creating: false);
        $e = Webhooks::update($this->endpoint($request, $id), $this->token($request)->user, array_filter([
            'url' => $d['url'] ?? null, 'events' => isset($d['events']) ? array_values((array) $d['events']) : null, 'active' => isset($d['active']) ? (bool) $d['active'] : null,
        ], fn ($v) => $v !== null) + (array_key_exists('description', $d) ? ['description' => $d['description']] : []));

        return response()->json(Resources::webhookEndpoint($e));
    }

    public function destroy(Request $request, string $id): Response
    {
        Webhooks::delete($this->endpoint($request, $id), $this->token($request)->user);

        return response()->noContent();
    }

    public function test(Request $request, string $id): JsonResponse
    {
        return response()->json(Resources::webhookDelivery(Webhooks::ping($this->endpoint($request, $id))), 201);
    }

    public function rotate(Request $request, string $id): JsonResponse
    {
        $e = $this->endpoint($request, $id);
        $secret = Webhooks::rotate($e, $this->token($request)->user);

        return response()->json([...Resources::webhookEndpoint($e->refresh()), 'secret' => $secret]);
    }

    public function deliveries(Request $request, string $id): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:pending,sending,retrying,succeeded,failed'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:500']]);
        $page = WebhookDelivery::query()->where('webhook_endpoint_id', $this->endpoint($request, $id)->id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->cursorPaginate($this->limit($request));

        return response()->json(Resources::page($page, array_values(array_map(fn (WebhookDelivery $d) => Resources::webhookDelivery($d), $page->items()))));
    }

    public function resend(Request $request, string $id): JsonResponse
    {
        $d = WebhookDelivery::query()->where('ulid', $id)->whereIn('webhook_endpoint_id', $this->query($request)->select('id'))->firstOrFail();

        return response()->json(Resources::webhookDelivery(Webhooks::resend($d)), 201);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<WebhookEndpoint> */
    private function query(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $token = $this->token($request);

        return WebhookEndpoint::query()->where(['team_id' => $token->team_id, 'mode' => $token->mode]);
    }

    private function endpoint(Request $request, string $id): WebhookEndpoint
    {
        return $this->query($request)->where('ulid', $id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'url' => [$creating ? 'required' : 'sometimes', 'string', 'max:500', 'url:https'],
            'events' => ['sometimes', 'array', 'min:1'], 'events.*' => ['string', 'in:*,'.implode(',', array_keys(WebhookEvents::TYPES))],
            'description' => ['sometimes', 'nullable', 'string', 'max:120'], 'active' => ['sometimes', 'boolean'],
        ]);
    }
}
