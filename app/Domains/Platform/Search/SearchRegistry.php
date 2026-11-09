<?php

declare(strict_types=1);

namespace App\Domains\Platform\Search;

use App\Domains\Identity\Models\User;
use Throwable;

final class SearchRegistry
{
    /** @var array<string, SearchSource> */
    private array $sources = [];

    public function register(SearchSource $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    /** @return list<array{key: string, label: string, items: list<array{title: string, subtitle: ?string, url: string}>}> */
    public function search(string $query, User $user, int $limit = 6): array
    {
        $out = [];
        foreach ($this->sources as $source) {
            if (! $source->allows($user)) {
                continue;
            }
            try {
                $items = $source->search($query, $user, $limit);
            } catch (Throwable $e) {
                report($e); // A down search engine must not break the palette; other sources still answer.
                $items = [];
            }
            if ($items !== []) {
                $out[] = ['key' => $source->key(), 'label' => $source->label(), 'items' => $items];
            }
        }

        return $out;
    }
}
