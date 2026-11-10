<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', fn (Blueprint $t) => $t->timestamp('premium_since')->nullable()->after('show_deal_value'));
        Schema::table('billing_customers', fn (Blueprint $t) => $t->string('card_warned_for', 7)->nullable()->after('needs_new_card')->comment('YYYY-MM of the expiry already warned about'));
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason', 500);
            $table->string('stripe_refund_id', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::table('billing_customers', fn (Blueprint $t) => $t->dropColumn('card_warned_for'));
        Schema::table('teams', fn (Blueprint $t) => $t->dropColumn('premium_since'));
    }
};
