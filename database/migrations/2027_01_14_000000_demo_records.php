<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every record the demo workspace creates, so a reset removes exactly those and nothing else.
        Schema::create('demo_records', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->comment('user, team, seat, referral, owner, post, event');
            $table->unsignedBigInteger('ref_id');
            $table->unsignedBigInteger('extra_id')->nullable()->comment('For owners: the network');
            $table->index(['type', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_records');
    }
};
