<?php

declare(strict_types=1);

namespace App\Domains\Platform\Search;

use App\Domains\Identity\Models\User;

/** A kind of thing the command palette can find. Each domain registers its own (teams, cities and referrals arrive with their sprints). */
interface SearchSource
{
    public function key(): string;

    public function label(): string;

    /** Whether this person may see results from this source at all. */
    public function allows(User $user): bool;

    /** @return list<array{title: string, subtitle: ?string, url: string}> */
    public function search(string $query, User $user, int $limit): array;
}
