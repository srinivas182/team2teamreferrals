<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Templates;

/**
 * Show-when rules on form fields. A field with `when: {field: "side", in: ["Selling", "Both"]}` is asked, required
 * and kept only when the earlier answer matches; otherwise it is skipped and any answer to it is dropped. One rule
 * per field keeps forms easy to read for the admins who build them.
 */
final class FieldRules
{
    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $answers
     */
    public static function shows(array $field, array $answers): bool
    {
        $when = $field['when'] ?? null;
        if (! is_array($when) || empty($when['field'])) {
            return true;
        }
        $given = $answers[(string) $when['field']] ?? null;
        $allowed = array_map('strval', (array) ($when['in'] ?? []));
        $values = is_array($given) ? array_map('strval', $given) : ($given === null || $given === '' ? [] : [is_bool($given) ? ($given ? 'Yes' : 'No') : (string) $given]);

        return array_intersect($values, $allowed) !== [];
    }

    /**
     * Keeps only a valid rule (the field it depends on, and the answers that show it).
     *
     * @param  array<mixed>  $when
     * @return array{field: string, in: list<string>}|null
     */
    public static function clean(mixed $when): ?array
    {
        if (! is_array($when) || empty($when['field'])) {
            return null;
        }
        $in = array_values(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 80), (array) ($when['in'] ?? [])), fn ($o) => $o !== ''));

        return $in === [] ? null : ['field' => (string) $when['field'], 'in' => $in];
    }
}
