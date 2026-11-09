<?php

declare(strict_types=1);

namespace App\Domains\Platform\Search;

use App\Domains\Identity\Models\User;

/** Settings and admin screens by name ("quiet hours", "templates", "stripe"), filtered by permission. */
final class PagesSource implements SearchSource
{
    /** @var list<array{0: string, 1: string, 2: string, 3: ?string}> title, keywords, url, permission (null = everyone) */
    private const PAGES = [
        ['Security settings', 'password passkey two-step 2fa devices sign out', '/app/settings/security', null],
        ['Notification settings', 'quiet hours digest email text push alerts', '/app/settings/notifications', null],
        ['Notifications', 'inbox bell unread', '/app/notifications', null],
        ['System settings', 'email sms twilio stripe payments storage s3 ai anthropic modules maintenance export', '/admin/system', 'settings.manage'],
        ['Delivery log', 'emails texts failed bounced suppressed', '/admin/deliveries', 'settings.manage'],
        ['Message templates', 'wording email text versions', '/admin/templates', 'content.manage'],
        ['Staff', 'invite permissions deactivate', '/admin/staff', 'staff.manage'],
        ['Audit log', 'history who changed export csv', '/admin/audit', 'audit.view'],
    ];

    public function key(): string
    {
        return 'pages';
    }

    public function label(): string
    {
        return 'Go to';
    }

    public function allows(User $user): bool
    {
        return true;
    }

    public function search(string $query, User $user, int $limit): array
    {
        $q = mb_strtolower(trim($query));
        $out = [];
        foreach (self::PAGES as [$title, $keywords, $url, $permission]) {
            if ($permission !== null && ! ($user->isStaff() && $user->can($permission))) {
                continue;
            }
            if (str_contains(mb_strtolower($title.' '.$keywords), $q)) {
                $out[] = ['title' => $title, 'subtitle' => null, 'url' => $url];
            }
        }

        return array_slice($out, 0, $limit);
    }
}
