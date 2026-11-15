import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { AdminLayout } from '@/layouts/AdminLayout';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

export type Person = { id: number; name: string };
export type GrowthShared = {
    layout: 'admin' | 'portal';
    base: string;
    actions: { bulk: string; import: string; importConfirm: string; views: string };
    group: { slug: string; name: string } | null;
    stages: Record<string, string>;
    sources: Record<string, string>;
    importFields: Record<string, string>;
    people: Person[];
    industries: { id: number; name: string }[];
    me: number;
    status?: string;
    seo: Seo;
};

export const TEMP_TONE = { hot: 'danger', warm: 'warning', cold: 'neutral' } as const;

/** Admin → Growth, or a network owner's page: the same screens in the right frame. */
export function GrowthShell({ shared, title, children }: { shared: GrowthShared; title: string; children: ReactNode }) {
    const { auth } = usePage<SharedProps>().props;
    const body = (
        <div className="max-w-6xl space-y-5">
            <SeoHead seo={shared.seo} />
            {statusMessage(shared.status) && <Alert tone="success">{statusMessage(shared.status)}</Alert>}
            {children}
        </div>
    );
    return shared.layout === 'admin' ? (
        <AdminLayout title={title}>{body}</AdminLayout>
    ) : (
        <PortalLayout title={`${title}: ${shared.group?.name ?? ''}`} user={{ name: auth.user?.name ?? '', team: '' }}>
            {body}
        </PortalLayout>
    );
}
