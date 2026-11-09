<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agreement versions live in industry_templates (kind "agreement"); each must be approved before members can sign it.
        Schema::table('industry_templates', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('is_active');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->string('approved_counsel', 160)->nullable()->after('approved_by');
            $table->text('approval_note')->nullable()->after('approved_counsel');
        });

        // State-specific clauses, added automatically when the receiving team is in that state.
        Schema::create('agreement_state_addenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('state_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['industry_id', 'state_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_state_addenda');
        Schema::table('industry_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'approved_counsel', 'approval_note']);
        });
    }
};
