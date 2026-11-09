<?php

declare(strict_types=1);

namespace App\Domains\Identity\Authorization;

/**
 * Platform roles and the admin permission catalogue. Super admins can do everything
 * (Gate::before); staff get only the permissions assigned to them. Group-owner and
 * team roles are scoped to groups and teams and arrive with those domains.
 */
final class Permissions
{
    public const SUPER_ADMIN = 'super_admin';

    public const STAFF = 'staff';

    public const MEMBER = 'member';

    /** @var array<string, array<string, string>> area => [permission => label] */
    public const CATALOGUE = [
        'Network' => [
            'industries.manage' => 'Manage industries and their templates',
            'locations.manage' => 'Manage countries, states and cities',
            'groups.manage' => 'Create and manage groups, seats and waitlists',
            'members.manage' => 'Manage members, deactivate and reactivate teams',
            'licenses.verify' => 'Verify licenses',
        ],
        'Referrals' => [
            'referrals.view' => 'View the network CRM',
            'disputes.manage' => 'Resolve fee disputes and outcome flags',
        ],
        'Revenue' => [
            'revenue.view' => 'View revenue and invoices',
            'billing.manage' => 'Manage plans, fees, refunds and coupons',
        ],
        'Growth and content' => [
            'growth.manage' => 'Manage prospects and campaigns',
            'content.manage' => 'Edit the website, blog and templates',
            'training.manage' => 'Manage training videos and quizzes',
        ],
        'Platform' => [
            'ai.manage' => 'Manage AI providers and credits',
            'support.manage' => 'Answer support tickets',
            'staff.manage' => 'Invite staff and set their permissions',
            'settings.manage' => 'Change platform settings and providers',
            'audit.view' => 'View and export the audit log',
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(array_merge(...array_values(self::CATALOGUE)));
    }
}
