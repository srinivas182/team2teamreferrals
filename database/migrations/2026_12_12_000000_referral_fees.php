<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A fee for one referral, agreed by both teams before the agreement is signed (Tier A only).
        Schema::table('referrals', function (Blueprint $t) {
            $t->string('fee_type', 8)->nullable()->after('closing_value_cents')->comment('percent or flat, when agreed for this referral');
            $t->unsignedBigInteger('fee_value')->nullable()->after('fee_type')->comment('Basis points for percent; cents for flat');
            $t->json('fee_proposal')->nullable()->after('fee_value');
        });
        // The referral fee the receiving team owes the referring team. Team to Team never holds the money.
        Schema::create('referral_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('payer_team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('payee_team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('type', 8);
            $table->unsignedBigInteger('rate')->comment('Basis points for percent; cents for flat');
            $table->unsignedBigInteger('basis_cents')->nullable()->comment('Closing value the percentage applies to');
            $table->unsignedBigInteger('amount_cents');
            $table->date('due_on');
            $table->string('status', 10)->default('due')->comment('due, paid, received, disputed');
            $table->string('paid_reference', 120)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('statement_path')->nullable();
            $table->timestamps();
            $table->index(['payer_team_id', 'status']);
            $table->index(['payee_team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_fees');
        Schema::table('referrals', fn (Blueprint $t) => $t->dropColumn(['fee_type', 'fee_value', 'fee_proposal']));
    }
};
