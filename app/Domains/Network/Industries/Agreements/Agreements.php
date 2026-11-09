<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Agreements;

use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Tier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Agreement versions per industry (kind "agreement" in industry_templates).
 * The editor works on the newest version; members only ever sign the newest APPROVED version.
 *
 * @phpstan-type Clause array{key: string, title: string, text: string, required: bool, optional: bool, enabled: bool, fee: bool}
 */
final class Agreements
{
    private const KIND = 'agreement';

    /** @return array{clauses: list<Clause>, version: ?int, approved: bool, source: string} the draft being edited */
    public function current(Industry $industry): array
    {
        $row = $this->rows($industry)->firstWhere('is_active', true);
        if ($row === null) {
            return ['clauses' => AgreementDefaults::for($industry->tier), 'version' => null, 'approved' => false, 'source' => 'default'];
        }

        return ['clauses' => self::decode($row->body), 'version' => (int) $row->version, 'approved' => $row->approved_at !== null, 'source' => 'industry'];
    }

    /** @return array{clauses: list<Clause>, version: int}|null the version members sign, or null if none is approved yet */
    public function forSigning(Industry $industry): ?array
    {
        if ($industry->tier === Tier::Directory) {
            return null;
        }
        $row = DB::table('industry_templates')->where(['kind' => self::KIND, 'scope_type' => 'industry', 'scope_id' => $industry->id])
            ->whereNotNull('approved_at')->orderByDesc('version')->first();

        return $row === null ? null : ['clauses' => self::decode($row->body), 'version' => (int) $row->version];
    }

    /** @param array<int|string, mixed> $clauses */
    public function save(Industry $industry, array $clauses, ?int $userId): int
    {
        $clean = $this->validate($industry, $clauses);

        return DB::transaction(function () use ($industry, $clean, $userId) {
            $scope = ['kind' => self::KIND, 'scope_type' => 'industry', 'scope_id' => $industry->id];
            $version = (int) DB::table('industry_templates')->where($scope)->max('version') + 1;
            DB::table('industry_templates')->where($scope)->update(['is_active' => false]);
            DB::table('industry_templates')->insert([...$scope, 'version' => $version, 'body' => json_encode($clean), 'is_active' => true, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);

            return $version;
        });
    }

    /** Approves the current draft. If it is still the default wording, it is saved as a version first. */
    public function approve(Industry $industry, ?int $userId, string $counsel, string $note): int
    {
        $current = $this->current($industry);
        $version = $current['version'] ?? $this->save($industry, $current['clauses'], $userId);
        DB::table('industry_templates')->where(['kind' => self::KIND, 'scope_type' => 'industry', 'scope_id' => $industry->id, 'version' => $version])
            ->update(['approved_at' => now(), 'approved_by' => $userId, 'approved_counsel' => $counsel, 'approval_note' => $note]);

        return $version;
    }

    public function reset(Industry $industry): void
    {
        DB::table('industry_templates')->where(['kind' => self::KIND, 'scope_type' => 'industry', 'scope_id' => $industry->id])->update(['is_active' => false]);
    }

    /** @return list<Clause> */
    public function version(Industry $industry, int $version): array
    {
        $row = $this->rows($industry)->firstWhere('version', $version);
        abort_if($row === null, 404);

        return self::decode($row->body);
    }

    /** @return list<array{version: int, active: bool, approved: bool, approvedAt: ?string, counsel: ?string, note: ?string, when: string}> */
    public function history(Industry $industry): array
    {
        $out = [];
        foreach ($this->rows($industry) as $r) {
            $out[] = [
                'version' => (int) $r->version, 'active' => (bool) $r->is_active, 'approved' => $r->approved_at !== null,
                'approvedAt' => $r->approved_at ? (string) $r->approved_at : null,
                'counsel' => $r->approved_counsel !== null ? (string) $r->approved_counsel : null,
                'note' => $r->approval_note !== null ? (string) $r->approval_note : null,
                'when' => (string) $r->created_at,
            ];
        }

        return $out;
    }

    /**
     * Compliance and structure rules. Errors block saving.
     *
     * @param  array<int|string, mixed>  $clauses
     * @return list<Clause>
     */
    public function validate(Industry $industry, array $clauses): array
    {
        $errors = [];
        if ($industry->tier === Tier::Directory) {
            throw ValidationException::withMessages(['clauses' => 'Directory-only industries have no referral agreement; only client consent is recorded.']);
        }
        $allowFees = $industry->tier->allowsMemberReferralFees();
        $required = array_column(array_filter(AgreementDefaults::for($industry->tier), fn ($c) => $c['required']), 'key');
        $seen = [];
        $clean = [];
        foreach (array_values($clauses) as $i => $c) {
            $c = is_array($c) ? $c : [];
            $key = (string) ($c['key'] ?? '');
            $title = trim((string) ($c['title'] ?? ''));
            $text = trim((string) ($c['text'] ?? ''));
            if (! preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key) || isset($seen[$key])) {
                $errors["clauses.{$i}.key"] = 'Each clause needs a unique key (lowercase letters, numbers, underscores).';
            }
            $seen[$key] = true;
            if ($title === '' || $text === '') {
                $errors["clauses.{$i}.text"] = 'Every clause needs a title and wording.';
            }
            preg_match_all('/\{([a-z_]+)\}/', $text, $m);
            foreach ($m[1] as $field) {
                if (! array_key_exists($field, AgreementDefaults::FIELDS)) {
                    $errors["clauses.{$i}.text"] = "Unknown merge field {{$field}}.";
                } elseif (! $allowFees && in_array($field, AgreementDefaults::FEE_FIELDS, true)) {
                    $errors["clauses.{$i}.text"] = 'This industry’s tier doesn’t allow referral fees, so fee fields can’t be used.';
                }
            }
            $fee = (bool) ($c['fee'] ?? false);
            if ($fee && ! $allowFees) {
                $errors["clauses.{$i}.text"] = 'This industry’s tier doesn’t allow referral fees, so fee clauses can’t be added.';
            }
            if (! $allowFees && preg_match('/\b(referral fee|commission split|finder\'?s fee|pay the referring party)\b/i', $text) && $key !== 'no_fee') {
                $errors["clauses.{$i}.text"] = 'This wording describes a referral payment, which this industry’s tier doesn’t allow.';
            }
            $clean[] = ['key' => $key, 'title' => mb_substr($title, 0, 120), 'text' => mb_substr($text, 0, 4000), 'required' => in_array($key, $required, true), 'optional' => (bool) ($c['optional'] ?? false), 'enabled' => in_array($key, $required, true) || (bool) ($c['enabled'] ?? true), 'fee' => $fee];
        }
        foreach ($required as $key) {
            if (! isset($seen[$key])) {
                $errors['clauses'] = 'Required clauses can’t be removed: '.implode(', ', array_diff($required, array_keys($seen))).'.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /** @return \Illuminate\Support\Collection<int, stdClass> */
    private function rows(Industry $industry)
    {
        return DB::table('industry_templates')->where(['kind' => self::KIND, 'scope_type' => 'industry', 'scope_id' => $industry->id])->orderByDesc('version')->get();
    }

    /** @return list<Clause> */
    private static function decode(mixed $body): array
    {
        /** @var list<Clause> $c */
        $c = json_decode((string) $body, true) ?: [];

        return $c;
    }
}
