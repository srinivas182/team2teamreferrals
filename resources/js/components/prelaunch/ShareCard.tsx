import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';

export type CardSize = 'og' | 'square' | 'story';
const LABEL: Record<CardSize, string> = { og: 'Link preview (1200×630)', square: 'Square (1080×1080)', story: 'Story (1080×1920)' };

/** Server-rendered "We claimed …" cards: the same images social networks show, ready to download and post. */
export function ShareCards({ shareId, territory }: { shareId: string; territory: string }) {
    const name = territory.toLowerCase().replace(/[^a-z0-9]+/g, '-');
    return (
        <div className="grid gap-4 sm:grid-cols-3">
            {(Object.keys(LABEL) as CardSize[]).map((s) => (
                <div key={s} className="space-y-2">
                    <img
                        src={`/reserve/c/${shareId}/${s}.png`}
                        alt={`Share card: we claimed ${territory}`}
                        loading="lazy"
                        className="w-full rounded-md border border-line"
                    />
                    <Button variant="secondary" size="sm" className="w-full" asChild>
                        <a href={`/reserve/c/${shareId}/${s}.png`} download={`we-claimed-${name}-${s}.png`}>
                            <Download /> {LABEL[s]}
                        </a>
                    </Button>
                </div>
            ))}
        </div>
    );
}
