/** Mirrors App\Domains\Seo\SeoData::toArray(). */
export type Seo = {
    title: string;
    description: string;
    canonical: string;
    robots: string;
    image: string;
    type: string;
    siteName: string;
    locale: string;
    schema: Record<string, unknown>[];
    modifiedAt: string | null;
};
