import { Link, router, usePage } from '@inertiajs/react';
import { BellOff, Settings } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Segmented } from '@/components/ui/data';
import { PortalLayout } from '@/layouts/PortalLayout';
import { cn } from '@/lib/utils';
import type { NotificationItem, SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

export default function Notifications({
    items,
    filter,
    pagination,
    seo,
}: {
    items: NotificationItem[];
    filter: 'all' | 'unread';
    pagination: { current: number; last: number };
    seo: Seo;
}) {
    const { auth, notifications } = usePage<SharedProps>().props;
    return (
        <PortalLayout
            title="Notifications"
            user={{ name: auth.user?.name ?? '', team: '' }}
            actions={
                <>
                    {(notifications?.unread ?? 0) > 0 && (
                        <Button variant="secondary" onClick={() => router.post('/app/notifications/read-all', {}, { preserveScroll: true })}>
                            Mark all read
                        </Button>
                    )}
                    <Button variant="ghost" asChild>
                        <Link href="/app/settings/notifications">
                            <Settings /> Settings
                        </Link>
                    </Button>
                </>
            }
        >
            <SeoHead seo={seo} />
            <div className="mb-4">
                <Segmented
                    label="Show"
                    value={filter}
                    onChange={(v) => router.get('/app/notifications', v === 'unread' ? { filter: 'unread' } : {}, { preserveState: true })}
                    options={[
                        ['all', 'All'],
                        ['unread', 'Unread'],
                    ]}
                />
            </div>
            <Card className="max-w-3xl">
                {items.length === 0 ? (
                    <div className="flex flex-col items-center px-6 py-16 text-center text-muted">
                        <BellOff className="size-8" aria-hidden />
                        <p className="mt-3">
                            {filter === 'unread' ? 'No unread notifications.' : 'Nothing yet. Referrals and updates will appear here.'}
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-line">
                        {items.map((n) => (
                            <li key={n.id}>
                                <button
                                    onClick={() => router.post(`/app/notifications/${n.id}/read`)}
                                    className={cn(
                                        'flex w-full items-start gap-3 px-5 py-4 text-left hover:bg-bg',
                                        !n.read && 'bg-cobalt-50/50 dark:bg-cobalt-500/10',
                                    )}
                                >
                                    <span
                                        className={cn('mt-2 size-2 shrink-0 rounded-full', n.read ? 'bg-transparent' : 'bg-primary')}
                                        aria-label={n.read ? undefined : 'Unread'}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{n.title}</span>
                                            <Badge>{n.group}</Badge>
                                        </div>
                                        <p className="mt-1 text-sm text-muted">{n.body}</p>
                                    </div>
                                    <span className="shrink-0 text-xs text-subtle">{n.ago}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
            {pagination.last > 1 && (
                <div className="mt-4 flex max-w-3xl justify-between">
                    <Button
                        variant="secondary"
                        size="sm"
                        disabled={pagination.current <= 1}
                        onClick={() => router.get('/app/notifications', { filter, page: pagination.current - 1 })}
                    >
                        Newer
                    </Button>
                    <Button
                        variant="secondary"
                        size="sm"
                        disabled={pagination.current >= pagination.last}
                        onClick={() => router.get('/app/notifications', { filter, page: pagination.current + 1 })}
                    >
                        Older
                    </Button>
                </div>
            )}
        </PortalLayout>
    );
}
