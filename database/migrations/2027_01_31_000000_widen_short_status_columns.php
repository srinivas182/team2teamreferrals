<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns were shorter than a value the app writes, which failed on MySQL (SQLite doesn't check lengths, so the
 * tests passed). Found when the tests started running on MySQL:
 * - cms_pages.status: "unpublished" (11) in 10, so taking a page down failed;
 * - nurture_touchpoints.kind: "client_not_needed" (17) in 16, so a client saying they no longer need help failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->string('status', 20)->default('draft')->comment('draft, scheduled, published, unpublished')->change();
        });
        Schema::table('nurture_touchpoints', function (Blueprint $table): void {
            $table->string('kind', 32)->comment('prompt, still, ready, lost, client_ready, client_later, client_not_needed, client_touch, upgrade')->change();
        });
    }

    public function down(): void
    {
        // Not narrowed again: rows written since may no longer fit.
    }
};
