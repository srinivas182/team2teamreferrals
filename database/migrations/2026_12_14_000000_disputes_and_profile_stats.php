<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_fees', function (Blueprint $t) {
            $t->text('dispute_reason')->nullable()->after('received_at');
            $t->timestamp('disputed_at')->nullable()->after('dispute_reason');
            $t->string('resolution', 10)->nullable()->after('disputed_at')->comment('confirmed, adjusted or waived');
            $t->text('resolution_note')->nullable()->after('resolution');
            $t->foreignId('resolved_by')->nullable()->after('resolution_note')->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable()->after('resolved_by');
        });
        Schema::table('referrals', function (Blueprint $t) {
            $t->text('close_resolution_note')->nullable()->after('close_dispute');
        });
        // Closed-deal value is commercially sensitive: hidden on profiles unless the team chooses to show it.
        Schema::table('teams', fn (Blueprint $t) => $t->boolean('show_deal_value')->default(false)->after('quick_replies'));
    }

    public function down(): void
    {
        Schema::table('teams', fn (Blueprint $t) => $t->dropColumn('show_deal_value'));
        Schema::table('referrals', fn (Blueprint $t) => $t->dropColumn('close_resolution_note'));
        Schema::table('referral_fees', fn (Blueprint $t) => $t->dropConstrainedForeignId('resolved_by'));
        Schema::table('referral_fees', fn (Blueprint $t) => $t->dropColumn(['dispute_reason', 'disputed_at', 'resolution', 'resolution_note', 'resolved_at']));
    }
};
