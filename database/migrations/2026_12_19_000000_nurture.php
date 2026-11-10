<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $t) {
            // From the form's timeline answer: hot (within 30 days), warm (1–3 months), future (later or exploring).
            $t->string('temperature', 8)->nullable()->after('status');
            $t->date('nurture_next_at')->nullable()->after('temperature');
            // The fee protection period; extended while the receiver keeps nurturing the lead.
            $t->date('protection_until')->nullable()->after('nurture_next_at');
            $t->index(['temperature', 'nurture_next_at']);
        });
        Schema::create('nurture_touchpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->comment('prompt, still, ready, lost, client_ready, client_later, client_not_needed, upgrade');
            $table->string('source', 8)->comment('system, pro or client');
            $table->string('note', 1000)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['referral_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nurture_touchpoints');
        Schema::table('referrals', function (Blueprint $t) {
            $t->dropIndex(['temperature', 'nurture_next_at']);
            $t->dropColumn(['temperature', 'nurture_next_at', 'protection_until']);
        });
    }
};
