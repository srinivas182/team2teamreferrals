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
            return response('', 200);
        }
        // Payment results for our invoices (e.g. after the customer confirmed with their bank).
        $invoiceId = (int) data_get($event, 'data.object.metadata.invoice_id', 0);
        if ($invoiceId > 0) {
            match ((string) $event['type']) {
                'payment_intent.succeeded' => app(\App\Domains\Billing\Invoicing::class)->paid($invoiceId),
                'payment_intent.payment_failed' => app(\App\Domains\Billing\Invoicing::class)->failedFromWebhook($invoiceId, (string) data_get($event, 'data.object.last_payment_error.message', 'The payment failed.')),
                default => null,
            };
        }

        return response('', 200);
    }
}
