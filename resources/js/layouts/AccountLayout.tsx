import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';

/**
 * The account pages (My account, Password and security, Notifications) are shared by members and staff: staff see
 * them inside Admin, so opening My account doesn't switch them to the member area's look and menu.
 */
export function AccountLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth } = usePage<SharedProps>().props;
    if (auth.user?.isStaff) {
        return <AdminLayout title={title}>{children}</AdminLayout>;
    }

    return (
        <PortalLayout title={title} user={{ name: auth.user?.name ?? '', team: '' }}>
            {children}
        </PortalLayout>
    );
}
