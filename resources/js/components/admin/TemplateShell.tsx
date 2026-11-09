import { Link, router, usePage } from '@inertiajs/react';
import { RotateCcw, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';

type Shared = { flash: { status?: string | null; warnings?: string[] | null }; errors: Record<string, string> };

/** Common frame for the form and pipeline editors: source badge, errors, warnings to confirm, version history. */
export function TemplateShell({
    title,
    slug,
    kind,
    source,
    version,
    history,
    onSave,
    saving,
    children,
}: {
    title: string;
    slug: string;
    kind: 'form' | 'stages';
    source: string;
    version: number | null;
    history: { version: number; active: boolean; when: string }[];
    onSave: (acknowledge: boolean) => void;
    saving: boolean;
    children: ReactNode;
}) {
    const { flash, errors } = usePage<Shared>().props;
    const errorList = Object.entries(errors)
        .filter(([k]) => k.startsWith('body'))
        .map(([, v]) => v);
    return (
        <AdminLayout
            title={title}
            actions={
                <>
                    {source === 'industry' && (
                        <Button variant="ghost" onClick={() => router.delete(`/admin/industries/${slug}/${kind}`, { preserveScroll: true })}>
                            <RotateCcw /> Use family default
                        </Button>
                    )}
                    <Button loading={saving} onClick={() => onSave(false)}>
                        Save as new version
                    </Button>
                </>
            }
        >
            <Link href={`/admin/industries/${slug}`} className="text-sm text-muted hover:text-ink">
                Back to industry
            </Link>
            <div className="mt-2 mb-4 flex flex-wrap items-center gap-2 text-sm">
                {source === 'family' ? <Badge>Using the family default</Badge> : <Badge tone="info">Customised · version {version}</Badge>}
                {history.length > 0 && (
                    <span className="text-muted">
                        {history.length} saved version{history.length > 1 ? 's' : ''}
                    </span>
                )}
            </div>
            {flash.status && (
                <Alert tone="success" className="mb-4 max-w-3xl">
                    {statusMessage(flash.status)}
                </Alert>
            )}
            {errorList.length > 0 && (
                <Alert tone="danger" className="mb-4 max-w-3xl">
                    <ul className="list-disc pl-4">
                        {errorList.map((e) => (
                            <li key={e}>{e}</li>
                        ))}
                    </ul>
                </Alert>
            )}
            {flash.warnings && flash.warnings.length > 0 && (
                <div className="mb-4 max-w-3xl rounded-md bg-[#FEF9E7] p-4 text-sm text-warning dark:bg-warning/15" role="alert">
                    <div className="flex items-center gap-2 font-semibold">
                        <TriangleAlert className="size-4" aria-hidden /> Check before saving
                    </div>
                    <ul className="mt-2 list-disc pl-5">
                        {flash.warnings.map((w) => (
                            <li key={w}>{w}</li>
                        ))}
                    </ul>
                    <Button size="sm" variant="secondary" className="mt-3" loading={saving} onClick={() => onSave(true)}>
                        Save anyway
                    </Button>
                </div>
            )}
            {children}
        </AdminLayout>
    );
}
