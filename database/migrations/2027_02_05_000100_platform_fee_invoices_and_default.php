<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform fees are charged as soon as they're owed, on an invoice of their own (source "fee:<id>"), not on the
 * monthly bill. And every industry where a platform fee is allowed (tier A) gets the default rule: a flat $250 per
 * Won referral. The rule stays off until fees are unlocked and the industry is approved (Admin → Industries).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('source_key', 40)->default('monthly')->after('period')->comment('monthly, or fee:<referral_fees.id> for a platform fee charged on its own');
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropUnique(['owner_type', 'owner_id', 'period']);
            $t->unique(['owner_type', 'owner_id', 'period', 'source_key']);
        });
        foreach (DB::table('industries')->where('tier', 'A')->pluck('id') as $id) {
            if (! DB::table('platform_fee_rules')->where(['industry_id' => $id])->whereNull('group_id')->exists()) {
                DB::table('platform_fee_rules')->insert(['industry_id' => $id, 'group_id' => null, 'mode' => 'flat', 'flat_cents' => 25000, 'charge_at' => 'closing', 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropUnique(['owner_type', 'owner_id', 'period', 'source_key']);
            $t->unique(['owner_type', 'owner_id', 'period']);
        });
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('source_key'));
    }
};
