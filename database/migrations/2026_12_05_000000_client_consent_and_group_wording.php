<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Texting clients stays off per industry until the platform (or counsel) approves it.
        Schema::table('industries', function (Blueprint $t) {
            $t->boolean('client_texting_approved')->default(false)->after('training_locked');
        });
        Schema::table('referrals', function (Blueprint $t) {
            $t->string('consent_token_hash', 64)->nullable()->unique()->after('consent_text');
            $t->timestamp('consent_requested_at')->nullable()->after('consent_token_hash');
            $t->timestamp('client_confirmed_at')->nullable()->after('consent_requested_at');
        });
        // A group owner's own wording for referral messages; falls back to the industry's, then the platform's.
        Schema::create('group_message_wording', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('channel', 12);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['group_id', 'type', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_message_wording');
        Schema::table('referrals', fn (Blueprint $t) => $t->dropColumn(['consent_token_hash', 'consent_requested_at', 'client_confirmed_at']));
        Schema::table('industries', fn (Blueprint $t) => $t->dropColumn('client_texting_approved'));
    }
};
