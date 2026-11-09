<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /** Keeps roles and the permission catalogue in step with the code on every deploy. */
    public function up(): void
    {
        Artisan::call('t2t:permissions:sync');
    }

    public function down(): void
    {
        // Roles and permissions are left in place; removing them would strip access from staff.
    }
};
