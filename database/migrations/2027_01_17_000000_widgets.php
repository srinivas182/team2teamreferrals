<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Embeddable widgets (Sprint 42): where a team's widgets may appear, daily widget stats, and which referrals came from one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->json('widget_domains')->nullable()->after('website')->comment('Websites (hostnames) where this team\'s widgets may appear');
        });
        Schema::table('profile_leads', function (Blueprint $table) {
            $table->string('source', 12)->default('profile')->after('team_id')->comment('profile, or the widget it came from: badge, reviews or button');
        });
        Schema::create('widget_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('widget', 10)->comment('badge, reviews or button');
            $table->string('kind', 10)->comment('view, click or referral');
            $table->string('domain', 190)->default('');
            $table->unsignedInteger('count')->default(0);
            $table->unique(['team_id', 'day', 'widget', 'kind', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_stats');
        Schema::table('profile_leads', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('widget_domains'));
    }
};
