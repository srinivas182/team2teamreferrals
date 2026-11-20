<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Reset for launch: clears everything members and the demo created, and keeps what the platform is made of
 * (industries, groups and their pricing, places, website pages, help, settings, templates) and its staff accounts.
 * Industries and groups the demo opened go back to draft: the demo's "live" was not a compliance sign-off.
 *
 * Run it through deploy/bin/reset-for-launch.sh on the server, which takes a database backup first.
 *
 * Every table is listed in exactly one of KEEP, STAFF_ROWS or WIPE (a test checks), so a table added later can't be
 * wiped or kept by accident.
 */
final class LaunchResetCommand extends Command
{
    /** What the platform is made of. */
    public const KEEP = [
        'migrations', 'settings', 'roles', 'permissions', 'role_has_permissions', 'menus', 'redirects', 'gone_urls',
        'states', 'counties', 'cities', 'city_aliases', 'metros', 'county_metro',
        'industries', 'industry_content', 'industry_licensing', 'industry_pairings', 'industry_templates', 'agreement_state_addenda',
        'groups', 'group_message_wording', 'group_scheduled_changes', 'platform_fee_rules', 'coupons', 'training_modules',
        'cms_pages', 'cms_page_versions', 'posts', 'blog_categories', 'help_articles', 'help_categories', 'support_macros',
        'notification_templates', 'campaigns', 'campaign_steps', 'ai_prompts', 'ally_evals', 'aeo_questions', 'partner_categories',
        'video_scripts', 'video_renders',
        // Opt-outs and bounces are kept: people who said stop stay stopped.
        'sms_opt_outs', 'email_suppressions',
    ];

    /** Rows of staff accounts are kept; everyone else's go. Column that names the user. */
    public const STAFF_ROWS = [
        'users' => 'id', 'model_has_roles' => 'model_id', 'model_has_permissions' => 'model_id', 'passkeys' => 'user_id',
        'user_devices' => 'user_id', 'push_subscriptions' => 'user_id', 'notification_preferences' => 'user_id', 'group_owners' => 'user_id',
    ];

    /** Everything members, teams, the demo and activity created. */
    public const WIPE = [
        'activity_log', 'aeo_results', 'ai_credit_ledger', 'ai_feedback', 'ai_suggestions', 'ai_summaries', 'ai_usage', 'ai_usage_logs',
        'ally_conversations', 'ally_messages', 'analytics_cursors', 'analytics_daily', 'analytics_events', 'api_idempotency_keys',
        'api_request_logs', 'api_tokens', 'billing_credits', 'billing_customers', 'billing_notices', 'bot_messages', 'bot_sessions',
        'cache', 'cache_locks', 'calendar_connections', 'calendar_items', 'campaign_enrollments', 'campaign_sends', 'chat_deliveries',
        'companies', 'company_invites', 'consumer_requests', 'contact_messages', 'coupon_redemptions', 'credit_ledger', 'dashboard_views',
        'demo_records', 'event_rsvps', 'events', 'failed_jobs', 'feed_comments', 'feed_posts', 'feed_reactions', 'feed_reports',
        'group_insights', 'help_feedback', 'help_searches', 'inbound_emails', 'intake_messages', 'integration_mappings', 'integrations',
        'invite_signups', 'invoices', 'job_batches', 'jobs', 'leaderboard_snapshots', 'media', 'member_badges', 'message_deliveries',
        'notification_outbox', 'notifications', 'nurture_touchpoints', 'onboarding_nudges', 'owner_checkins', 'owner_reward_seats',
        'partner_endorsements', 'partner_enquiries', 'partner_listings', 'partner_shares', 'partner_stats', 'partners',
        'password_reset_tokens', 'profile_leads', 'profile_stats', 'prospect_activities', 'prospect_tasks', 'prospect_views', 'prospects',
        'referral_agreements', 'referral_attachments', 'referral_drafts', 'referral_events', 'referral_fees', 'referral_messages',
        'referral_mutes', 'referral_ratings', 'referral_reads', 'referral_reminders', 'referral_scores', 'referrals', 'refunds',
        'report_cards', 'report_deliveries', 'report_recipient_runs', 'report_schedules', 'reservations', 'saved_reports',
        'seat_applications', 'seat_challenges', 'seat_events', 'seat_invites', 'seat_offers', 'seat_waitlist', 'seats',
        'seo_ai_visits', 'seo_checks', 'sessions', 'signup_attributions', 'support_messages', 'support_tickets', 'sync_logs',
        'team_invites', 'team_licenses', 'team_members', 'team_time_off', 'teams', 'testimonials', 'training_progress',
        'trust_score_history', 'trust_scores', 'usability_events', 'usability_issues', 'usability_sessions', 'webhook_deliveries',
        'webhook_endpoints', 'webhook_events', 'widget_stats', 'win_shares',
    ];

    public const DEMO_NOTE = 'Demo install: sample network, not a compliance sign-off.';

    protected $signature = 't2t:launch:reset {--confirm= : The site\'s domain, typed out, to confirm} {--dry-run : Show what would be cleared}';

    protected $description = 'Clear all member, team, referral and demo data for launch; keep industries, groups, places, pages, settings and staff';

    public function handle(): int
    {
        if ((bool) config('app.demo_install')) {
            $this->error('This is still a demo install. Remove DEMO_INSTALL from the settings (ENV_FILE) and deploy first, or the demo comes straight back.');

            return self::FAILURE;
        }
        $domain = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $staff = User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', [Permissions::SUPER_ADMIN, Permissions::STAFF]))->pluck('id')->all();
        $tables = collect(self::WIPE)->filter(fn ($t) => Schema::hasTable($t));
        $this->line('Keeps: industries, groups and their pricing, places, website pages, help, settings, templates and '.count($staff).' staff account(s).');
        $this->line('Clears: '.$tables->count().' tables of member, team, referral, billing and demo data, and every non-staff account ('.(User::query()->count() - count($staff)).').');
        if ($staff === []) {
            $this->error('No staff account would be left to sign in with. Create one first (t2t:super-admin).');

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if (strcasecmp((string) $this->option('confirm'), $domain) !== 0) {
            $this->error("To confirm, run it with --confirm={$domain}");

            return self::FAILURE;
        }

        Schema::disableForeignKeyConstraints();
        DB::beginTransaction();
        try {
            // Delete, not truncate: on MySQL a truncate commits at once and can't be rolled back with the rest.
            foreach ($tables as $t) {
                DB::table($t)->delete();
            }
            foreach (self::STAFF_ROWS as $table => $column) {
                if (Schema::hasTable($table)) {
                    $q = DB::table($table)->whereNotIn($column, $staff);
                    if (in_array($table, ['model_has_roles', 'model_has_permissions'], true)) {
                        $q->where('model_type', User::class);
                    }
                    $q->delete();
                }
            }
            // The demo's open networks go back to draft; real sign-offs stay.
            $demo = DB::table('industries')->where('compliance_note', self::DEMO_NOTE)->pluck('id');
            DB::table('industries')->whereIn('id', $demo)->update(['status' => 'draft', 'compliance_approved_at' => null, 'compliance_approved_by' => null, 'compliance_note' => null, 'updated_at' => now()]);
            DB::table('groups')->whereIn('industry_id', $demo)->where('status', 'live')->update(['status' => 'draft', 'updated_at' => now()]);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        } finally {
            Schema::enableForeignKeyConstraints();
        }
        Cache::flush();
        activity('admin')->event('launch-reset')->withProperties(['tables' => $tables->count(), 'staff_kept' => count($staff), 'industries_back_to_draft' => $demo->count()])->log('Data reset for launch');
        $this->info('Done. '.$demo->count().' demo-opened industr'.($demo->count() === 1 ? 'y is' : 'ies are').' back to draft. Staff accounts are kept; everyone else is signed out.');

        return self::SUCCESS;
    }
}
