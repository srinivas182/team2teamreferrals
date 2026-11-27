<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which cities can join a group. By default all cities; the owner (or staff) can limit it to a list, chosen state by
 * state. Only teams applying for a listed city can then apply; existing seats elsewhere are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', fn (Blueprint $t) => $t->boolean('cities_limited')->default(false)->comment('true: only the cities in group_cities can apply'));
        Schema::create('group_cities', function (Blueprint $t) {
            $t->foreignId('group_id')->constrained()->cascadeOnDelete();
            $t->foreignId('city_id')->constrained()->cascadeOnDelete();
            $t->primary(['group_id', 'city_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_cities');
        Schema::table('groups', fn (Blueprint $t) => $t->dropColumn('cities_limited'));
    }
};
