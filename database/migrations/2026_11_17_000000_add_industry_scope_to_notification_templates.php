<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Message wording can now be overridden per industry; null industry_id is the platform-wide wording. */
    public function up(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->foreignId('industry_id')->nullable()->after('channel')->constrained()->cascadeOnDelete();
            $table->dropUnique(['type', 'channel', 'version']);
            $table->unique(['type', 'channel', 'industry_id', 'version']);
            $table->index(['type', 'channel', 'industry_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->dropIndex(['type', 'channel', 'industry_id', 'is_active']);
            $table->dropUnique(['type', 'channel', 'industry_id', 'version']);
            $table->dropConstrainedForeignId('industry_id');
            $table->unique(['type', 'channel', 'version']);
        });
    }
};
