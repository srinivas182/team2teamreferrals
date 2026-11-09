<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Catalogue;

use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Templates\FamilyDefaults;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Industries\Templates\Schemas;
use App\Domains\Network\Industries\Tier;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Creates or refreshes the 46 launch industries. Safe to re-run: a value is only updated if it still
 * equals what the catalogue wrote last time, so anything an admin changed is left alone. Industry-specific
 * forms and pipelines are created once and never touched again.
 */
final class CatalogueSync
{
    private const SYNCED = ['name', 'family', 'tier', 'territory_unit', 'terminology', 'icon', 'description', 'license_required'];

    public function __construct(private readonly IndustryTemplates $templates) {}

    /** @return array{created: int, updated: int, kept: int} kept = values left alone because an admin changed them */
    public function run(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'kept' => 0];
        foreach (LaunchCatalogue::all() as $entry) {
            DB::transaction(function () use ($entry, &$stats) {
                /** @var array{0: string, 1: string, 2: string, 3: string} $terms */
                $terms = $entry['terms'];
                $slug = (string) $entry['slug'];
                $values = [
                    'name' => (string) $entry['name'], 'family' => (string) $entry['family'], 'tier' => (string) $entry['tier'], 'territory_unit' => (string) $entry['territory'],
                    'terminology' => ['pro' => $terms[0], 'pros' => $terms[1], 'client' => $terms[2], 'clients' => $terms[3]],
                    'icon' => (string) $entry['icon'], 'description' => (string) $entry['description'], 'license_required' => (bool) $entry['license'],
                ];
                $industry = Industry::query()->where('slug', $slug)->first();
                if ($industry === null) {
                    $industry = Industry::create(['slug' => $slug, ...$values, 'tier' => Tier::from($values['tier'])]);
                    $industry->forceFill(['catalogue_snapshot' => $values])->save();
                    $this->templatesFor($industry, $entry);
                    $stats['created']++;

                    return;
                }
                $snapshot = (array) ($industry->getAttribute('catalogue_snapshot') ?? []);
                $changed = false;
                foreach (self::SYNCED as $field) {
                    $current = $field === 'tier' ? $industry->tier->value : $industry->getAttribute($field);
                    $untouched = array_key_exists($field, $snapshot) && $snapshot[$field] == $current;
                    if (! $untouched) {
                        $stats['kept'] += (int) ($current != $values[$field]);
                        $values[$field] = $snapshot[$field] ?? $current; // remember what we last wrote, not the admin's value

                        continue;
                    }
                    if ($current != $values[$field]) {
                        $industry->setAttribute($field, $field === 'tier' ? Tier::from((string) $values[$field]) : $values[$field]);
                        $changed = true;
                    }
                }
                $industry->forceFill(['catalogue_snapshot' => $values])->save();
                $stats['updated'] += (int) $changed;
            });
        }

        return $stats;
    }

    /** @param array<string, mixed> $check */
    private static function assertValid(array $check, string $what): void
    {
        if (! empty($check['errors'])) {
            throw new LogicException("Launch catalogue {$what} is invalid: ".json_encode($check['errors']));
        }
    }

    /** @param array<string, mixed> $entry */
    private function templatesFor(Industry $industry, array $entry): void
    {
        if (! empty($entry['fields'])) {
            $form = FamilyDefaults::form($industry->family);
            $notes = array_pop($form);
            /** @var list<array<string, mixed>> $extra */
            $extra = $entry['fields'];
            /** @var list<array<string, mixed>> $body */
            $body = [...$form, ...$extra, $notes];
            self::assertValid(Schemas::checkForm($body), $industry->slug.' form'); // never seed a form the builder would reject
            $this->templates->save('form', $industry, $body, null);
        }
        if (! empty($entry['stages'])) {
            /** @var array<string, string> $names */
            $names = $entry['stages'];
            $body = array_map(fn (array $s) => isset($names[$s['key']]) ? [...$s, 'label' => $names[$s['key']]] : $s, FamilyDefaults::stages($industry->family));
            self::assertValid(Schemas::checkStages($body), $industry->slug.' pipeline');
            $this->templates->save('stages', $industry, $body, null);
        }
    }
}
