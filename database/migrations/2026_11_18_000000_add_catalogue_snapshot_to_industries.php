<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What the launch catalogue last wrote, so re-running it updates only values an admin hasn't changed. */
    public function up(): void
    {
        Schema::table('industries', fn (Blueprint $t) => $t->json('catalogue_snapshot')->nullable());
    }

    public function down(): void
    {
        Schema::table('industries', fn (Blueprint $t) => $t->dropColumn('catalogue_snapshot'));
    }
};
