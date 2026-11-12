import { Link, usePage } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

/** For owners of several networks: which one? */
export default function Choose({ page, groups, seo }: { page: string; groups: { slug: string; name: string }[]; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <PortalLayout title="Choose a network" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="grid max-w-3xl gap-3 sm:grid-cols-2">
                {groups.map((g) => (
                    <Link key={g.slug} href={`/app/group/${g.slug}${page ? `/${page}` : ''}`}>
                        <Card className="p-5 font-semibold hover:border-primary">{g.name}</Card>
                    </Link>
                ))}
            </div>
        </PortalLayout>
    );
}
