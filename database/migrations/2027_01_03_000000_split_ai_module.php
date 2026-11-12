<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The single "AI features" switch becomes one switch per AI module. Whatever was chosen before carries over to each
 * of the AI modules it covered, so nothing changes for an installation on upgrade.
 */
return new class extends Migration
{
    public const SPLIT = ['ai_summaries', 'ai_drafts', 'ai_intake', 'chat_bot', 'ai_insights'];

    public function up(): void
    {
        $old = DB::table('settings')->where('key', 'modules.ai')->first();
        if ($old === null) {
            return;
        }
        foreach (self::SPLIT as $key) {
            DB::table('settings')->updateOrInsert(['key' => 'modules.'.$key], ['value' => $old->value, 'is_secret' => false, 'updated_at' => now(), 'created_at' => now()]);
        }
        DB::table('settings')->where('key', 'modules.ai')->delete();
    }

    public function down(): void
    {
        $on = DB::table('settings')->where('key', 'modules.ai_summaries')->value('value');
        if ($on !== null) {
            DB::table('settings')->updateOrInsert(['key' => 'modules.ai'], ['value' => $on, 'is_secret' => false, 'updated_at' => now(), 'created_at' => now()]);
        }
        DB::table('settings')->whereIn('key', array_map(fn ($k) => 'modules.'.$k, self::SPLIT))->delete();
    }
};
