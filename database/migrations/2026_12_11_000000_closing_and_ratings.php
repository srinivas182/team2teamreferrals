<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $t) {
            // A Won close is confirmed by the sender (or disputes), or confirms itself after 7 days.
            $t->string('close_status', 16)->nullable()->after('closed_at')->comment('awaiting, confirmed, auto_confirmed, disputed');
            $t->timestamp('close_confirmed_at')->nullable()->after('close_status');
            $t->text('close_dispute')->nullable()->after('close_confirmed_at');
            $t->string('client_rating_token_hash', 64)->nullable()->unique()->after('client_confirmed_at');
            // The receiver's thank-you to the sender; shown on the sender's profile only if the sender agrees.
            $t->text('thanks_note')->nullable()->after('close_dispute');
            $t->timestamp('thanks_at')->nullable()->after('thanks_note');
            $t->boolean('thanks_public')->default(false)->after('thanks_at');
        });
        Schema::create('referral_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $table->string('rater', 10)->comment('sender, receiver or client');
            $table->foreignId('rater_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('rated_team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedTinyInteger('stars');
            $table->json('aspects')->nullable();
            $table->string('comment', 1000)->nullable();
            $table->timestamp('editable_until')->nullable();
            $table->timestamps();
            $table->unique(['referral_id', 'rater']);
            $table->index('rated_team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_ratings');
        Schema::table('referrals', fn (Blueprint $t) => $t->dropColumn(['close_status', 'close_confirmed_at', 'close_dispute', 'client_rating_token_hash', 'thanks_note', 'thanks_at', 'thanks_public']));
    }
};
