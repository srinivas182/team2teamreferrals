export type AuthUser = { id: string; name: string; firstName: string; email: string; isStaff: boolean; permissions: string[] };
export type NotificationItem = {
    id: string;
    type: string;
    group: string;
    title: string;
    body: string;
    url: string | null;
    read: boolean;
    ago: string;
};
export type SharedProps = {
    auth: { user: AuthUser | null };
    flash: { status?: string | null };
    notifications: { unread: number; latest: NotificationItem[] } | null;
};
