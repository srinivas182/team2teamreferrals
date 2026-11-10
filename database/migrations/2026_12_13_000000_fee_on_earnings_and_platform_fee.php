<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The receiving team's earnings from the referral (e.g. their commission): what a percentage fee is based on.
        Schema::table('referrals', fn (Blueprint $t) => $t->unsignedBigInteger('earnings_cents')->nullable()->after('closing_value_cents'));
        Schema::table('referral_fees', function (Blueprint $t) {
            $t->unsignedBigInteger('platform_fee_cents')->default(0)->after('amount_cents')->comment("Taken from the referring team's referral fee");
            $t->string('platform_fee_status', 10)->default('none')->after('platform_fee_cents')->comment('none, pending (until receipt), owed (billed in Sprint 22)');
            $t->timestamp('platform_fee_owed_at')->nullable()->after('platform_fee_status');
        });
    }

    public function down(): void
    {
        Schema::table('referral_fees', fn (Blueprint $t) => $t->dropColumn(['platform_fee_cents', 'platform_fee_status', 'platform_fee_owed_at']));
        Schema::table('referrals', fn (Blueprint $t) => $t->dropColumn('earnings_cents'));
    }
};
