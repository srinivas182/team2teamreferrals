<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One invoice per payer per month (a company gets one for all its branches), billed after the month ends.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->string('owner_type', 8);
            $table->unsignedBigInteger('owner_id');
            $table->string('period', 7);
            $table->json('lines');
            $table->unsignedBigInteger('total_cents');
            $table->string('status', 16)->comment('paid, open, failed, action_required, void, zero');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('failure', 255)->nullable();
            $table->string('stripe_payment_intent', 64)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id', 'period']);
            $table->index(['status', 'next_retry_at']);
        });
        // After three failed payments in 12 months, a new card is needed before reactivation.
        Schema::table('billing_customers', fn (Blueprint $t) => $t->boolean('needs_new_card')->default(false)->after('card_exp_year'));
    }

    public function down(): void
    {
        Schema::table('billing_customers', fn (Blueprint $t) => $t->dropColumn('needs_new_card'));
        Schema::dropIfExists('invoices');
    }
};
