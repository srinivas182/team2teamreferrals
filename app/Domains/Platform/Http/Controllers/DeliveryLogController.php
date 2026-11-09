<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Deliveries\MessageDelivery;
use App\Domains\Platform\Deliveries\ProviderHealth;
use App\Domains\Seo\SeoData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Every email and text with its status — answers "I never got the code" in seconds. */
final class DeliveryLogController
{
    public function __invoke(Request $request): Response
    {
        $page = MessageDelivery::query()->latest('id')
            ->when($request->filled('channel'), fn (Builder $q) => $q->where('channel', $request->string('channel')->toString()))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('q'), fn (Builder $q) => $q->where('recipient', 'like', '%'.$request->string('q')->toString().'%'))
            ->paginate(50)->withQueryString();

        return Inertia::render('admin/Deliveries', [
            'entries' => collect($page->items())->map(fn (MessageDelivery $d) => [
                'id' => $d->id,
                'channel' => $d->channel,
                'provider' => $d->provider,
                'recipient' => $d->recipient,
                'subject' => $d->subject,
                'status' => $d->status,
                'error' => $d->error,
                'when' => $d->created_at->toIso8601String(),
                'ago' => $d->created_at->diffForHumans(),
            ]),
            'pagination' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total()],
            'filters' => $request->only(['channel', 'status', 'q']),
            'health' => ['mail' => ProviderHealth::for('mail'), 'sms' => ProviderHealth::for('sms')],
            'seo' => SeoData::private('Delivery log')->toArray(),
        ]);
    }
}
