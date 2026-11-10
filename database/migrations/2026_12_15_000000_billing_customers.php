<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who pays: a team, or a company for company-paid seats. Card details stay with Stripe; we keep a reference.
        Schema::create('billing_customers', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 8)->comment('team or company');
            $table->unsignedBigInteger('owner_id');
            $table->string('stripe_customer_id', 64)->nullable()->unique();
            $table->string('payment_method_id', 64)->nullable();
            $table->string('card_brand', 20)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->unsignedTinyInteger('card_exp_month')->nullable();
            $table->unsignedSmallInteger('card_exp_year')->nullable();
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id']);
        });
        Schema::create('billing_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seat_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->timestamp('sent_at');
            $table->unique(['seat_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_notices');
        Schema::dropIfExists('billing_customers');
    }
};
