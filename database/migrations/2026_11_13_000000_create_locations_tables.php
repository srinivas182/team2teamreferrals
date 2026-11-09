<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->string('country', 2)->default('US');
            $table->string('code', 4);
            $table->string('name', 80);
            $table->string('fips', 2)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->unique(['country', 'code']);
        });

        Schema::create('counties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->constrained()->cascadeOnDelete();
            $table->string('fips', 5)->unique();
            $table->string('name', 100);
            $table->decimal('lat', 8, 5)->nullable();
            $table->decimal('lon', 8, 5)->nullable();
            $table->timestamps();
        });

        // Census core-based statistical areas: metropolitan and micropolitan.
        Schema::create('metros', function (Blueprint $table) {
            $table->id();
            $table->string('cbsa_code', 5)->unique();
            $table->string('name', 140);
            $table->string('kind', 12)->comment('metro or micro');
            $table->timestamps();
        });
        Schema::create('county_metro', function (Blueprint $table) {
            $table->foreignId('metro_id')->constrained()->cascadeOnDelete();
            $table->foreignId('county_id')->constrained()->cascadeOnDelete();
            $table->primary(['metro_id', 'county_id']);
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->constrained()->cascadeOnDelete();
            $table->foreignId('county_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->string('search_name', 140)->comment('normalised: lowercase, "st." → "saint"');
            $table->decimal('lat', 8, 5)->nullable();
            $table->decimal('lon', 8, 5)->nullable();
            $table->unsignedInteger('population')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->unique(['state_id', 'slug']);
            $table->index(['search_name', 'population']);
            $table->index(['is_enabled', 'population']);
        });

        Schema::create('city_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 120);
            $table->string('search_name', 140)->index();
            $table->timestamps();
            $table->unique(['city_id', 'search_name']);
        });
    }

    public function down(): void
    {
        foreach (['city_aliases', 'cities', 'county_metro', 'metros', 'counties', 'states'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
