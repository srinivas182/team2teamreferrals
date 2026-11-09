<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industries', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('family', 20);
            $table->char('tier', 1)->comment('A open, B restricted, C directory only');
            $table->string('territory_unit', 10)->comment('city, county, metro or state');
            $table->json('terminology')->comment('pro, pros, client, clients');
            $table->string('icon', 40)->default('Briefcase');
            $table->text('description')->nullable();
            $table->boolean('license_required')->default(true);
            $table->string('status', 10)->default('draft')->comment('draft or live');
            $table->timestamp('compliance_approved_at')->nullable();
            $table->foreignId('compliance_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('compliance_note')->nullable();
            $table->timestamps();
        });

        // Per-state licensing: who issues it and what a license number looks like.
        Schema::create('industry_licensing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('state_id')->constrained()->cascadeOnDelete();
            $table->boolean('required')->default(true);
            $table->string('issuing_body', 160)->nullable();
            $table->string('number_pattern', 120)->nullable()->comment('Regular expression a license number must match');
            $table->string('lookup_url', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['industry_id', 'state_id']);
        });

        // How the platform may charge per referral. Built now, charged only once switched on after legal sign-off.
        Schema::create('platform_fee_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industry_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('group_id')->nullable()->comment('null = industry default; groups arrive in Sprint 10');
            $table->string('mode', 10)->default('none')->comment('none, percent or flat');
            $table->decimal('percent', 5, 2)->nullable();
            $table->unsignedInteger('flat_cents')->nullable();
            $table->json('flat_by_temperature')->nullable();
            $table->string('charge_at', 12)->default('closing')->comment('acceptance or closing');
            $table->boolean('enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['industry_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fee_rules');
        Schema::dropIfExists('industry_licensing');
        Schema::dropIfExists('industries');
    }
};
