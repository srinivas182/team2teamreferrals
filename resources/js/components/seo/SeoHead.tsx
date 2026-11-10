import { Head } from '@inertiajs/react';
import type { Seo } from '@/types/seo';

/**
 * Renders every page's search and answer-engine metadata. Because pages are
 * server-rendered, crawlers receive this in the initial HTML.
 */
export function SeoHead({ seo }: { seo: Seo }) {
    return (
        <Head>
            <title>{seo.title}</title>
            <meta head-key="description" name="description" content={seo.description} />
            <meta head-key="robots" name="robots" content={seo.robots} />
            <link head-key="canonical" rel="canonical" href={seo.canonical} />
            <meta head-key="og:type" property="og:type" content={seo.type} />
            <meta head-key="og:site_name" property="og:site_name" content={seo.siteName} />
            <meta head-key="og:locale" property="og:locale" content={seo.locale} />
            <meta head-key="og:title" property="og:title" content={seo.title} />
            <meta head-key="og:description" property="og:description" content={seo.description} />
            <meta head-key="og:url" property="og:url" content={seo.canonical} />
            <meta head-key="og:image" property="og:image" content={seo.image} />
            <meta head-key="og:image:width" property="og:image:width" content="1200" />
            <meta head-key="og:image:height" property="og:image:height" content="630" />
            <meta head-key="twitter:card" name="twitter:card" content="summary_large_image" />
            {seo.modifiedAt && <meta head-key="article:modified_time" property="article:modified_time" content={seo.modifiedAt} />}
            {seo.schema.map((item, i) => (
                <script key={i} head-key={`ld-${i}`} type="application/ld+json">
                    {JSON.stringify(item).replace(/</g, '\\u003c')}
                </script>
            ))}
        </Head>
    );
}
