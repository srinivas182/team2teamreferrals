<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When no team holds the seat in the client's city, the sender can pick a nearby team. Keep the client's own city and
 * how far it is from that team's seat, so the receiver knows the lead is outside their city and admins can see which
 * empty cities members want to send to (seats worth selling).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->foreignId('client_city_id')->nullable()->after('seat_id')->constrained('cities')->nullOnDelete();
            $table->unsignedSmallInteger('distance_miles')->nullable()->after('client_city_id');
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_city_id');
            $table->dropColumn('distance_miles');
        });
    }
};
