<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The client can stop the occasional "still thinking about it?" emails with one click; honoured permanently.
        Schema::table('referrals', fn (Blueprint $t) => $t->timestamp('client_emails_stopped_at')->nullable()->after('client_confirmed_at'));
    }

    public function down(): void
    {
        Schema::table('referrals', fn (Blueprint $t) => $t->dropColumn('client_emails_stopped_at'));
    }
};
