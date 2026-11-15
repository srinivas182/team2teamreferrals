<?php

declare(strict_types=1);

namespace App\Domains\Content\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A message arrived through the Contact page. */
final class ContactMessageReceived
{
    use Dispatchable;

    public function __construct(public readonly int $messageId) {}
}
