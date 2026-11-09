<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A company with many city teams (branches); its admin sees every branch and chooses who pays for each seat.
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name', 160);
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
        Schema::table('teams', fn (Blueprint $t) => $t->foreignId('company_id')->nullable()->after('leader_id')->constrained()->nullOnDelete());
        Schema::table('seats', fn (Blueprint $t) => $t->string('payer', 8)->default('team')->after('founding')->comment('team or company'));
        Schema::table('groups', fn (Blueprint $t) => $t->foreignId('company_id')->nullable()->after('parent_group_id')->comment('Owner of a private network')->constrained()->nullOnDelete());
        Schema::create('company_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->string('status', 10)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invites');
        Schema::table('groups', fn (Blueprint $t) => $t->dropConstrainedForeignId('company_id'));
        Schema::table('seats', fn (Blueprint $t) => $t->dropColumn('payer'));
        Schema::table('teams', fn (Blueprint $t) => $t->dropConstrainedForeignId('company_id'));
        Schema::dropIfExists('companies');
    }
};
