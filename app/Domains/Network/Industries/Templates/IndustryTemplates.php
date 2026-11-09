<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Templates;

use App\Domains\Network\Industries\Models\Industry;
use Illuminate\Support\Facades\DB;

/**
 * Which form or pipeline applies: the group's override (Sprint 10), else the industry's, else the family default.
 * Saving creates a new version; resetting returns to the family default.
 */
final class IndustryTemplates
{
    public const KINDS = ['form', 'stages'];

    /** @return array{body: list<array<string, mixed>>, source: string, version: ?int} */
    public function resolve(string $kind, Industry $industry, ?int $groupId = null): array
    {
        foreach (array_filter([['group', $groupId], ['industry', $industry->id]], fn ($s) => $s[1] !== null) as [$type, $id]) {
            $row = DB::table('industry_templates')->where(['kind' => $kind, 'scope_type' => $type, 'scope_id' => $id, 'is_active' => true])->first();
            if ($row !== null) {
                /** @var list<array<string, mixed>> $body */
                $body = json_decode((string) $row->body, true);

                return ['body' => $body, 'source' => $type, 'version' => (int) $row->version];
            }
        }

        return ['body' => $kind === 'form' ? FamilyDefaults::form($industry->family) : FamilyDefaults::stages($industry->family), 'source' => 'family', 'version' => null];
    }

    /** @param list<array<string, mixed>> $body */
    public function save(string $kind, Industry $industry, array $body, ?int $userId, ?int $groupId = null): int
    {
        return DB::transaction(function () use ($kind, $industry, $body, $userId, $groupId) {
            $scope = self::scope($kind, $industry, $groupId);
            $version = (int) DB::table('industry_templates')->where($scope)->max('version') + 1;
            DB::table('industry_templates')->where($scope)->update(['is_active' => false]);
            DB::table('industry_templates')->insert([...$scope, 'version' => $version, 'body' => json_encode($body), 'is_active' => true, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);

            return $version;
        });
    }

    public function reset(string $kind, Industry $industry, ?int $groupId = null): void
    {
        DB::table('industry_templates')->where(self::scope($kind, $industry, $groupId))->update(['is_active' => false]);
    }

    /** @return list<array{version: int, active: bool, when: string}> */
    public function history(string $kind, Industry $industry, ?int $groupId = null): array
    {
        $out = [];
        foreach (DB::table('industry_templates')->where(self::scope($kind, $industry, $groupId))->orderByDesc('version')->limit(20)->get() as $r) {
            $out[] = ['version' => (int) $r->version, 'active' => (bool) $r->is_active, 'when' => (string) $r->created_at];
        }

        return $out;
    }

    /** @return array{kind: string, scope_type: string, scope_id: int} a group's override, or the industry's own version */
    public static function scope(string $kind, Industry $industry, ?int $groupId): array
    {
        return ['kind' => $kind, 'scope_type' => $groupId === null ? 'industry' : 'group', 'scope_id' => $groupId ?? $industry->id];
    }
}
