<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Annual prepay: chosen per seat, billed on the next monthly invoice, covering the next 12 months.
        Schema::table('seats', function (Blueprint $t) {
            $t->string('billing_cycle', 8)->default('monthly')->after('payer');
            $t->date('annual_paid_until')->nullable()->after('billing_cycle');
        });
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->unsignedTinyInteger('percent_off')->nullable();
            $table->unsignedInteger('amount_off_cents')->nullable();
            $table->unsignedTinyInteger('months')->default(1);
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redeemed')->default(0);
            $table->date('expires_on')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->string('owner_type', 8);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedTinyInteger('months_left');
            $table->timestamps();
            $table->unique(['coupon_id', 'owner_type', 'owner_id']);
        });
        // Account credits (e.g. free months on annual plans, goodwill): used up on later invoices.
        Schema::create('billing_credits', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 8);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('used_cents')->default(0);
            $table->string('reason', 255);
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });
        Schema::table('referral_fees', fn (Blueprint $t) => $t->foreignId('platform_invoice_id')->nullable()->after('platform_fee_owed_at')->constrained('invoices')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('referral_fees', fn (Blueprint $t) => $t->dropConstrainedForeignId('platform_invoice_id'));
        Schema::dropIfExists('billing_credits');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::table('seats', fn (Blueprint $t) => $t->dropColumn(['billing_cycle', 'annual_paid_until']));
    }
};
