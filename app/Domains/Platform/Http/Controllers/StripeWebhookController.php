<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Payments\Stripe;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Stores each verified Stripe event once. Billing (Sprint 22) processes them; retries are harmless. */
final class StripeWebhookController
{
    public function __invoke(Request $request, Settings $settings): Response
    {
        $secret = (string) $settings->get('payments.stripe.webhook_secret');
        $payload = $request->getContent();
        abort_unless($secret !== '' && Stripe::validSignature($payload, (string) $request->header('Stripe-Signature'), $secret), 400);

        $event = json_decode($payload, true);
        abort_unless(is_array($event) && isset($event['id'], $event['type']), 400);
        try {
            DB::table('webhook_events')->insert(['provider' => 'stripe', 'event_id' => (string) $event['id'], 'type' => (string) $event['type'], 'payload' => $payload, 'created_at' => now(), 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            // Already received: Stripe retried. Acknowledge so it stops.
        }

        return response('', 200);
    }
}
