<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage', fn (Blueprint $t) => $t->string('provider', 20)->default('anthropic')->after('month'));
        // Versioned prompt templates: one active version per key; history kept for rollback.
        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);
            $table->unsignedInteger('version');
            $table->text('system');
            $table->text('template');
            $table->boolean('active')->default(false);
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['key', 'version']);
        });
        // A team's AI credits: monthly allowance, purchases (added to the next invoice), usage and expiry.
        Schema::create('ai_credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12)->comment('allowance, expire, purchase, usage, adjust');
            $table->integer('credits');
            $table->string('period', 7)->nullable()->comment('YYYY-MM, for allowances');
            $table->unsignedInteger('price_cents')->nullable()->comment('Purchases: charged on the next invoice');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('usage_id')->nullable();
            $table->timestamp('created_at');
            $table->index(['team_id', 'kind']);
            $table->unique(['team_id', 'kind', 'period']);
        });
        // Every AI call, metered against the team that asked for it.
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature', 40);
            $table->string('prompt_key', 60)->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->unsignedInteger('credits')->default(0);
            $table->string('status', 12)->comment('ok, cached, failed, blocked');
            $table->string('reason', 120)->nullable();
            $table->timestamp('created_at');
            $table->index(['team_id', 'created_at']);
        });
        // One summary per referral, reused until the referral's details change.
        Schema::create('ai_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('input_hash', 64);
            $table->text('summary');
            $table->foreignId('usage_id')->nullable()->constrained('ai_usage_logs')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usage_id')->constrained('ai_usage_logs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('rating')->comment('1 helpful, -1 not helpful');
            $table->timestamp('created_at');
            $table->unique(['usage_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['ai_feedback', 'ai_summaries', 'ai_usage_logs', 'ai_credit_ledger', 'ai_prompts'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('ai_usage', fn (Blueprint $t) => $t->dropColumn('provider'));
    }
};
