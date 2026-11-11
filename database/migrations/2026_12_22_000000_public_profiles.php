<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $t) {
            $t->string('slug', 80)->nullable()->unique()->after('name');
            $t->boolean('profile_public')->default(true)->after('slug');
        });
        foreach (DB::table('teams')->orderBy('id')->get(['id', 'name']) as $t) {
            $base = Str::slug((string) $t->name) ?: 'team';
            $slug = $base;
            for ($i = 2; DB::table('teams')->where('slug', $slug)->exists(); $i++) {
                $slug = $base.'-'.$i;
            }
            DB::table('teams')->where('id', $t->id)->update(['slug' => $slug]);
        }
        // Daily counts per team: views, QR scans, link clicks, referrals from the profile.
        Schema::create('profile_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('kind', 12)->comment('view, qr, link, referral');
            $table->unsignedInteger('count')->default(0);
            $table->unique(['team_id', 'day', 'kind']);
        });
        // A referral from someone outside the network, sent from a team's public profile (after email confirmation).
        Schema::create('profile_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('referrer_name', 120);
            $table->string('referrer_email', 190);
            $table->string('referrer_business', 160)->nullable();
            $table->string('client_name', 120);
            $table->string('client_phone', 32)->nullable();
            $table->string('client_email', 190)->nullable();
            $table->text('need');
            $table->text('consent_text');
            $table->string('status', 12)->default('unverified')->comment('unverified, new, contacted, closed');
            $table->string('verify_token_hash', 64)->nullable()->unique();
            $table->timestamp('verified_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_leads');
        Schema::dropIfExists('profile_stats');
        Schema::table('teams', fn (Blueprint $t) => $t->dropColumn(['slug', 'profile_public']));
    }
};
