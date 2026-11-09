<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('name');
            $table->text('bio')->nullable()->after('logo_path');
            $table->string('website')->nullable()->after('bio');
            $table->string('phone', 20)->nullable()->after('website');
            $table->json('social')->nullable()->after('phone')->comment('linkedin, facebook, instagram, youtube, x');
            $table->string('service_area', 500)->nullable()->after('social')->comment('Plain words: "Austin and Travis County"');
            $table->unsignedSmallInteger('founded_year')->nullable()->after('service_area');
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->comment('leader, member, assistant');
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });

        Schema::create('team_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 12);
            $table->string('token_hash', 64)->unique();
            $table->string('status', 10)->default('pending');
            $table->timestamp('expires_at');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Every existing team's leader becomes its first member.
        foreach (DB::table('teams')->get(['id', 'leader_id']) as $t) {
            DB::table('team_members')->insert(['team_id' => $t->id, 'user_id' => $t->leader_id, 'role' => 'leader', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invites');
        Schema::dropIfExists('team_members');
        Schema::table('teams', fn (Blueprint $t) => $t->dropColumn(['logo_path', 'bio', 'website', 'phone', 'social', 'service_area', 'founded_year']));
    }
};
