<?php

declare(strict_types=1);

namespace App\Domains\Seo\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatch whenever public pages are created, changed or removed
 * (a seat filled, a group published, a blog post edited…).
 */
final class PublicUrlsChanged
{
    use Dispatchable;

    /** @param list<string> $paths */
    public function __construct(public readonly array $paths) {}
}
