import { SeoHead } from '@/components/seo/SeoHead';
import { CmsBlock, type Block } from '@/components/cms/Blocks';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = {
    page: { title: string; summary: string; template: string; updated: string; blocks: Block[] };
    preview: { version: number } | null;
    seo: Seo;
};

/** A website page built in Admin → Website: the answer first, then its blocks. */
export default function CmsPage({ page, preview, seo }: Props) {
    return (
        <PublicLayout current="">
            <SeoHead seo={seo} />
            {preview && (
                <div className="bg-warning/20 px-4 py-2 text-center text-sm font-medium">
                    Preview of version {preview.version}: not public, not indexed.
                </div>
            )}
            <article className="mx-auto max-w-5xl px-4 py-10">
                <header className="max-w-3xl">
                    <h1 className="font-display text-4xl font-bold">{page.title}</h1>
                    {page.summary && <p className="mt-4 text-lg">{page.summary}</p>}
                    {page.template === 'legal' && <p className="mt-2 text-sm text-muted">Last updated {page.updated}</p>}
                </header>
                {page.blocks.map((b, i) => (
                    <CmsBlock key={i} b={b} />
                ))}
            </article>
        </PublicLayout>
    );
}
