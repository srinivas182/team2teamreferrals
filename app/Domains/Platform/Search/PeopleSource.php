<?php

declare(strict_types=1);

namespace App\Domains\Platform\Search;

use App\Domains\Identity\Models\User;

/** Members and staff by name or email, for staff who manage people. Uses Meilisearch in production (typo-tolerant). */
final class PeopleSource implements SearchSource
{
    public function key(): string
    {
        return 'people';
    }

    public function label(): string
    {
        return 'People';
    }

    public function allows(User $user): bool
    {
        return $user->isStaff() && ($user->can('members.manage') || $user->can('staff.manage'));
    }

    public function search(string $query, User $user, int $limit): array
    {
        $results = User::search($query)->take($limit)->get();
        $out = [];
        foreach ($results as $u) {
            $out[] = ['title' => $u->name, 'subtitle' => $u->email.($u->isStaff() ? ' · staff' : ''), 'url' => $u->isStaff() ? '/admin/staff' : '/admin/audit?q='.urlencode($u->email)];
        }

        return $out;
    }
}
