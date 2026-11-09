<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('is_private')->default(false)->after('is_launch')->comment('A company’s own network: no public seats, never listed');
            $table->string('region', 12)->nullable()->after('is_private')->comment('northeast, midwest, south, west; null = nationwide');
            $table->foreignId('parent_group_id')->nullable()->after('region')->comment('The group this one overflowed from')->constrained('groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_group_id');
            $table->dropColumn(['is_private', 'region']);
        });
    }
};
