<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public API (Sprint 44a): team API tokens (live and test), saved responses for safe retries, and the sandbox
 * that test tokens work in (a private network of sample teams, so nothing reaches a real member or client).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->comment('Who created it; the API acts as this person')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('mode', 4)->comment('live or test (test tokens work in the sandbox)');
            $table->char('token_hash', 64)->unique()->comment('SHA-256 of the token; the token itself is shown once');
            $table->string('prefix', 16)->comment('First characters, to recognise it in lists');
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'revoked_at']);
        });
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_token_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->char('request_hash', 64)->comment('Method, path and body, so the same key with a different request is refused');
            $table->unsignedSmallInteger('status')->nullable()->comment('Null while the first request is still running');
            $table->longText('body')->nullable();
            $table->timestamp('created_at');
            $table->unique(['api_token_id', 'key']);
            $table->index('created_at');
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('sandbox_of_team_id')->nullable()->after('leader_id')
                ->comment('Set on a sandbox twin: the real team whose test tokens act as this team')->constrained('teams')->cascadeOnDelete();
        });
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('is_sandbox')->default(false)->after('is_private')->comment('The API sandbox network (private, sample teams only)');
        });
    }

    public function down(): void
    {
        Schema::table('groups', fn (Blueprint $table) => $table->dropColumn('is_sandbox'));
        Schema::table('teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sandbox_of_team_id');
        });
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('api_tokens');
    }
};
