<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Each reservation gets a public share id (its share and invite link) and remembers who invited it. */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->ulid('share_id')->nullable()->unique()->after('id');
            $table->foreignId('referred_by')->nullable()->after('on_wall')->constrained('reservations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by');
            $table->dropColumn('share_id');
        });
    }
};
