<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Templates;

/**
 * Checks forms and pipelines before they're saved. Errors block the save; warnings point out
 * things later features need (and must be acknowledged).
 */
final class Schemas
{
    public const FIELD_TYPES = ['text' => 'Short text', 'textarea' => 'Long text', 'number' => 'Number', 'money_range' => 'Money range', 'choice' => 'One choice', 'multi_choice' => 'Several choices', 'date' => 'Date', 'boolean' => 'Yes / no'];

    public const ROLES = ['value' => 'Estimated value (shown to the receiver before accepting)', 'timeline' => 'Timeline (sets the lead temperature)'];

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public static function checkForm(array $fields): array
    {
        $errors = [];
        $warnings = [];
        $keys = [];
        $roles = [];
        foreach ($fields as $i => $f) {
            $n = $i + 1;
            $key = (string) ($f['key'] ?? '');
            $label = trim((string) ($f['label'] ?? ''));
            if (! preg_match('/^[a-z][a-z0-9_]{1,39}$/', $key)) {
                $errors[] = "Field {$n}: the key must be 2–40 lowercase letters, numbers or underscores.";
            } elseif (isset($keys[$key])) {
                $errors[] = "Field {$n}: “{$key}” is used twice.";
            }
            $keys[$key] = true;
            if ($label === '') {
                $errors[] = "Field {$n}: add a label.";
            }
            $type = (string) ($f['type'] ?? '');
            if (! isset(self::FIELD_TYPES[$type])) {
                $errors[] = "Field {$n}: choose a field type.";
            }
            if (in_array($type, ['choice', 'multi_choice'], true) && count(array_filter((array) ($f['options'] ?? []), fn ($o) => trim((string) $o) !== '')) < 2) {
                $errors[] = "“{$label}”: give at least two options.";
            }
            if (isset($f['role'])) {
                $role = (string) $f['role'];
                if (! isset(self::ROLES[$role])) {
                    $errors[] = "“{$label}”: unknown role.";
                } elseif (isset($roles[$role])) {
                    $errors[] = 'Only one field can be the '.strtolower(explode(' (', self::ROLES[$role])[0]).'.';
                }
                $roles[$role] = true;
                if ($role === 'value' && $type !== 'money_range') {
                    $errors[] = "“{$label}”: the estimated value must be a money range.";
                }
            }
        }
        if (count($fields) > 30) {
            $errors[] = 'Keep forms to 30 fields or fewer; long forms stop members sending referrals.';
        }
        foreach (['client_name', 'client_phone'] as $must) {
            if (! isset($keys[$must])) {
                $errors[] = "The form must keep the “{$must}” field: the receiving pro needs it to make contact.";
            }
        }
        if (! isset($roles['value'])) {
            $warnings[] = 'No field is marked as the estimated value, so receivers won’t see an estimated referral value before accepting.';
        }
        if (! isset($roles['timeline'])) {
            $warnings[] = 'No field is marked as the timeline, so the lead temperature (Hot, Warm, Future) must be picked by hand.';
        }
        foreach ($fields as $f) {
            if (($f['sensitive'] ?? false) === false && preg_match('/phone|email|address|ssn|dob|birth/i', (string) ($f['key'] ?? '').' '.(string) ($f['label'] ?? ''))) {
                $warnings[] = '“'.$f['label'].'” looks like personal contact information. Mark it sensitive so it never appears on public pages.';
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public static function checkStages(array $stages): array
    {
        $errors = [];
        $keys = [];
        $kinds = ['open' => 0, 'won' => 0, 'lost' => 0];
        foreach ($stages as $i => $s) {
            $key = (string) ($s['key'] ?? '');
            if (! preg_match('/^[a-z][a-z0-9_]{1,39}$/', $key) || isset($keys[$key])) {
                $errors[] = 'Stage '.($i + 1).': use a unique key of lowercase letters, numbers or underscores.';
            }
            $keys[$key] = true;
            if (trim((string) ($s['label'] ?? '')) === '') {
                $errors[] = 'Stage '.($i + 1).': add a name.';
            }
            if (isset($s['client_label']) && mb_strlen((string) $s['client_label']) > 80) {
                $errors[] = 'Stage '.($i + 1).': keep the client-friendly name under 80 characters.';
            }
            if (isset($s['limit_days']) && $s['limit_days'] !== '' && (! is_numeric($s['limit_days']) || (int) $s['limit_days'] < 1 || (int) $s['limit_days'] > 90)) {
                $errors[] = 'Stage '.($i + 1).': a time limit must be between 1 and 90 days.';
            }
            $kind = (string) ($s['kind'] ?? '');
            if (! isset($kinds[$kind])) {
                $errors[] = 'Stage '.($i + 1).': choose open, won or lost.';
            } else {
                $kinds[$kind]++;
            }
        }
        if (($stages[0]['kind'] ?? null) !== 'open') {
            $errors[] = 'The first stage must be an open stage (where new referrals land).';
        }
        if ($kinds['won'] !== 1 || $kinds['lost'] !== 1) {
            $errors[] = 'A pipeline needs exactly one Won stage and one Lost stage (they drive closing reports and the trust score).';
        }
        $warnings = $kinds['open'] < 3 ? ['Fewer than three open stages makes progress hard to see for the sender.'] : [];

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Validation rules for a submitted referral (used when sending, Sprint 16).
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, list<mixed>>
     */
    public static function rules(array $fields): array
    {
        $rules = [];
        foreach ($fields as $f) {
            $k = 'fields.'.$f['key'];
            $base = [($f['required'] ?? false) ? 'required' : 'nullable'];
            $rules += match ($f['type']) {
                'text' => [$k => [...$base, 'string', 'max:200']],
                'textarea' => [$k => [...$base, 'string', 'max:3000']],
                'number' => [$k => [...$base, 'numeric', 'min:0']],
                'date' => [$k => [...$base, 'date']],
                'boolean' => [$k => [($f['required'] ?? false) ? 'present' : 'nullable', 'boolean']],
                'choice' => [$k => [...$base, 'in:'.implode(',', array_map(fn ($o) => str_replace(',', '\,', (string) $o), (array) $f['options']))]],
                'multi_choice' => [$k => [...$base, 'array'], $k.'.*' => ['in:'.implode(',', array_map(fn ($o) => str_replace(',', '\,', (string) $o), (array) $f['options']))]],
                'money_range' => [$k => [...$base, 'array'], $k.'.min' => ['nullable', 'integer', 'min:0'], $k.'.max' => ['nullable', 'integer', 'gte:'.$k.'.min']],
                default => [],
            };
        }

        return $rules;
    }
}
