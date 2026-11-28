<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sharing reward replaces the free extension: a team that sends a good referral in a month (accepted, and the
 * client contacted) gets its seat free the next month. On for every Tier A group; seats are $25 a month.
 *
 * A receiver can mark an accepted client "not a real client" (invalid_*): it then counts against the sender, not the
 * receiver's score, and the sender can ask the group owner to review it (invalid_disputed_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        $tiers = DB::table('industries')->pluck('tier', 'id');
        foreach (DB::table('groups')->get(['id', 'industry_id', 'pricing']) as $g) {
            $p = (array) json_decode((string) $g->pricing, true);
            unset($p['value_guarantee']);
            $p['sharing_reward'] = ($tiers[$g->industry_id] ?? null) === 'A';
            $p['seat_price'] = 25;
            $p['founding_price'] = 25;
            DB::table('groups')->where('id', $g->id)->update(['pricing' => json_encode($p)]);
        }
        Schema::table('referrals', function (Blueprint $t) {
            $t->timestamp('invalid_at')->nullable()->comment('Receiver marked the client not real');
            $t->string('invalid_reason', 30)->nullable();
            $t->text('invalid_note')->nullable();
            $t->foreignId('invalid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('invalid_disputed_at')->nullable()->comment('Sender asked the group owner to review');
            $t->index(['sender_team_id', 'invalid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $t) {
            $t->dropIndex(['sender_team_id', 'invalid_at']);
            $t->dropConstrainedForeignId('invalid_by');
            $t->dropColumn(['invalid_at', 'invalid_reason', 'invalid_note', 'invalid_disputed_at']);
        });
    }
};
