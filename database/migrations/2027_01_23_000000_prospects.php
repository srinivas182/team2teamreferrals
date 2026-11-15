<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 46a: the prospect CRM for staff and group owners (people who might join a network), with a timeline,
 * tasks, saved views, and a suppression list of addresses that must never be emailed again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('email', 190)->unique()->comment('Lower-case');
            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('business', 160)->nullable();
            $table->string('phone', 32)->nullable();
            $table->foreignId('industry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state_code', 2)->nullable();
            $table->string('stage', 12)->default('new')->comment('new, contacted, interested, signed_up, applied, joined, lost');
            $table->string('lost_reason', 255)->nullable();
            $table->foreignId('owner_id')->nullable()->comment('The staff member or group owner working it')->constrained('users')->nullOnDelete();
            $table->string('source', 20)->comment('signup, reservation, contact_form, import, manual');
            $table->string('source_detail', 190)->nullable()->comment('e.g. the inviting team, the import file, the reservation city');
            $table->string('consent_basis', 30)->comment('How they came to us: signed_up, reserved, wrote_to_us, imported_not_purchased, added_by_staff');
            $table->foreignId('user_id')->nullable()->unique()->comment('Their account, once they sign up')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('score')->default(0)->comment('Lead score: engagement and how far they got');
            $table->json('tags')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('alerted_at')->nullable()->comment('Last "seat opened in your city" alert');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['stage', 'last_activity_at']);
            $table->index(['group_id', 'stage']);
            $table->index(['industry_id', 'city_id']);
            $table->index('owner_id');
        });

        Schema::create('prospect_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->comment('Who did it (null: automatic)')->constrained()->nullOnDelete();
            $table->string('type', 20)->comment('created, note, stage, owner, task, email, alert, signup, applied, joined, import, score');
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at');
            $table->index(['prospect_id', 'id']);
        });

        Schema::create('prospect_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 190);
            $table->timestamp('due_at');
            $table->timestamp('done_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['assignee_id', 'done_at', 'due_at']);
        });

        Schema::create('prospect_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('filters');
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email_hash', 64)->unique()->comment('sha256 of the lower-case address: kept after a "forget me" without keeping the address');
            $table->string('reason', 20)->comment('unsubscribed, bounced, complained, forgotten');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
        Schema::dropIfExists('prospect_views');
        Schema::dropIfExists('prospect_tasks');
        Schema::dropIfExists('prospect_activities');
        Schema::dropIfExists('prospects');
    }
};
