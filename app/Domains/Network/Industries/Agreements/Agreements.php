<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Agreements;

use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
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
    public function current(Industry $industry, ?int $groupId = null): array
    {
        $row = $this->rows($industry, $groupId)->firstWhere('is_active', true);
        if ($row === null) {
            // A group without its own draft starts from the industry's current wording.
            return $groupId === null
                ? ['clauses' => AgreementDefaults::for($industry->tier), 'version' => null, 'approved' => false, 'source' => 'default']
                : [...$this->current($industry), 'version' => null, 'approved' => false, 'source' => 'industry'];
        }

        return ['clauses' => self::decode($row->body), 'version' => (int) $row->version, 'approved' => $row->approved_at !== null, 'source' => $groupId === null ? 'industry' : 'group'];
    }

    /** @return array{clauses: list<Clause>, version: int, scope: string}|null the version members sign, or null if none is approved yet */
    public function forSigning(Industry $industry, ?int $groupId = null): ?array
    {
        if ($industry->tier === Tier::Directory) {
            return null;
        }
        // A group's own approved version wins; otherwise its members sign the industry's approved version. Drafts are never signed.
        foreach (array_filter([$groupId, 0], fn ($v) => $v !== null) as $scopeId) {
            $row = DB::table('industry_templates')->where(IndustryTemplates::scope(self::KIND, $industry, $scopeId === 0 ? null : $scopeId))
                ->whereNotNull('approved_at')->orderByDesc('version')->first();
            if ($row !== null) {
                return ['clauses' => self::decode($row->body), 'version' => (int) $row->version, 'scope' => $scopeId === 0 ? 'industry' : 'group'];
            }
        }

        return null;
    }

    /** @param array<int|string, mixed> $clauses */
    public function save(Industry $industry, array $clauses, ?int $userId, ?int $groupId = null): int
    {
        $clean = $this->validate($industry, $clauses);

        return DB::transaction(function () use ($industry, $clean, $userId, $groupId) {
            $scope = IndustryTemplates::scope(self::KIND, $industry, $groupId);
            $version = (int) DB::table('industry_templates')->where($scope)->max('version') + 1;
            DB::table('industry_templates')->where($scope)->update(['is_active' => false]);
            DB::table('industry_templates')->insert([...$scope, 'version' => $version, 'body' => json_encode($clean), 'is_active' => true, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);

            return $version;
        });
    }

    /** Approves the current draft. If it is still the default wording, it is saved as a version first. */
    public function approve(Industry $industry, ?int $userId, string $counsel, string $note, ?int $groupId = null): int
    {
        $current = $this->current($industry, $groupId);
        $version = $current['version'] ?? $this->save($industry, $current['clauses'], $userId, $groupId);
        DB::table('industry_templates')->where([...IndustryTemplates::scope(self::KIND, $industry, $groupId), 'version' => $version])
            ->update(['approved_at' => now(), 'approved_by' => $userId, 'approved_counsel' => $counsel, 'approval_note' => $note]);

        return $version;
    }

    public function reset(Industry $industry, ?int $groupId = null): void
    {
        DB::table('industry_templates')->where(IndustryTemplates::scope(self::KIND, $industry, $groupId))->update(['is_active' => false]);
    }

    /** @return list<Clause> */
    public function version(Industry $industry, int $version, ?int $groupId = null): array
    {
        $row = $this->rows($industry, $groupId)->firstWhere('version', $version);
        abort_if($row === null, 404);

        return self::decode($row->body);
    }

    /** @return list<array{version: int, active: bool, approved: bool, approvedAt: ?string, counsel: ?string, note: ?string, when: string}> */
    public function history(Industry $industry, ?int $groupId = null): array
    {
        $out = [];
        foreach ($this->rows($industry, $groupId) as $r) {
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
    private function rows(Industry $industry, ?int $groupId = null)
    {
        return DB::table('industry_templates')->where(IndustryTemplates::scope(self::KIND, $industry, $groupId))->orderByDesc('version')->get();
    }

    /** @return list<Clause> */
    private static function decode(mixed $body): array
    {
        /** @var list<Clause> $c */
        $c = json_decode((string) $body, true) ?: [];

        return $c;
    }
}
