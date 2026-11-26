<?php

declare(strict_types=1);

use App\Domains\Network\Industries\Templates\FamilyDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Saved property forms (commercial real estate, property management...) were copied from the family defaults before
 * the "address of the home they're selling" question existed. Add it after "Buying or selling?" as a new version,
 * so admins can roll back from the form builder. Forms without that question are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $address = collect(FamilyDefaults::form('property'))->firstWhere('key', 'property_address');
        foreach (DB::table('industry_templates')->where(['kind' => 'form', 'is_active' => true])->get() as $row) {
            /** @var list<array<string, mixed>> $body */
            $body = json_decode((string) $row->body, true) ?: [];
            $keys = array_column($body, 'key');
            $at = array_search('side', $keys, true);
            if ($at === false || in_array('property_address', $keys, true)) {
                continue;
            }
            array_splice($body, $at + 1, 0, [$address]);
            $scope = ['kind' => 'form', 'scope_type' => $row->scope_type, 'scope_id' => $row->scope_id];
            $version = (int) DB::table('industry_templates')->where($scope)->max('version') + 1;
            DB::table('industry_templates')->where($scope)->update(['is_active' => false]);
            DB::table('industry_templates')->insert([...$scope, 'version' => $version, 'body' => json_encode($body), 'is_active' => true, 'created_by' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Older versions stay in the history; an admin can switch back from the form builder.
    }
};
