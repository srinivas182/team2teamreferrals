import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';

export type CardSize = 'og' | 'square' | 'story';
const DIMS: Record<CardSize, [number, number]> = { og: [1200, 630], square: [1080, 1080], story: [1080, 1920] };
const LABEL: Record<CardSize, string> = { og: 'Link preview (1200×630)', square: 'Square (1080×1080)', story: 'Story (1080×1920)' };

const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** "We claimed Houston" card as an SVG string, so it can be shown and turned into a PNG for download. */
export function shareSvg(size: CardSize, business: string, territory: string, group: string): string {
    const [w, h] = DIMS[size];
    const big = Math.round(w * (territory.length > 16 ? 0.06 : 0.08));
    const y0 = size === 'story' ? h * 0.38 : h * 0.34;
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">
<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0E1B3D"/><stop offset="1" stop-color="#1B2A5C"/></linearGradient><linearGradient id="rl" x1="0" x2="1"><stop offset="0" stop-color="#2451F5"/><stop offset="1" stop-color="#FF7A1A"/></linearGradient></defs>
<rect width="${w}" height="${h}" fill="url(#bg)"/><circle cx="${w * 0.88}" cy="${h * 0.12}" r="${w * 0.22}" fill="#FF7A1A" opacity=".18"/><circle cx="${w * 0.1}" cy="${h * 0.9}" r="${w * 0.25}" fill="#2451F5" opacity=".28"/>
<path d="M${w * 0.12} ${h * 0.78} C ${w * 0.35} ${h * 0.55}, ${w * 0.65} ${h * 0.55}, ${w * 0.88} ${h * 0.72}" fill="none" stroke="url(#rl)" stroke-width="${w * 0.008}" stroke-linecap="round"/>
<circle cx="${w * 0.12}" cy="${h * 0.78}" r="${w * 0.015}" fill="#2451F5"/><circle cx="${w * 0.88}" cy="${h * 0.72}" r="${w * 0.015}" fill="#FF7A1A"/>
<text x="${w * 0.07}" y="${y0 - big * 1.1}" fill="#AAB4CE" font-family="Inter, Arial, sans-serif" font-size="${Math.round(w * 0.028)}" font-weight="600">${esc(group.toUpperCase())}</text>
<text x="${w * 0.07}" y="${y0}" fill="#fff" font-family="Inter, Arial, sans-serif" font-size="${big}" font-weight="800">We claimed</text>
<text x="${w * 0.07}" y="${y0 + big * 1.15}" fill="#fff" font-family="Inter, Arial, sans-serif" font-size="${big}" font-weight="800">${esc(territory)}.</text>
<text x="${w * 0.07}" y="${y0 + big * 2.1}" fill="#C9D2EA" font-family="Inter, Arial, sans-serif" font-size="${Math.round(w * 0.03)}">${esc(business)} · founding member</text>
<text x="${w * 0.93}" y="${h * 0.93}" text-anchor="end" fill="#fff" font-family="Inter, Arial, sans-serif" font-size="${Math.round(w * 0.026)}" font-weight="700">teamtoteamreferrals.com</text>
</svg>`;
}

async function downloadPng(size: CardSize, svg: string, name: string) {
    const [w, h] = DIMS[size];
    const img = new Image();
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    await img.decode();
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    canvas.getContext('2d')?.drawImage(img, 0, 0, w, h);
    const a = document.createElement('a');
    a.href = canvas.toDataURL('image/png');
    a.download = `${name}-${size}.png`;
    a.click();
}

export function ShareCards({ business, territory, group }: { business: string; territory: string; group: string }) {
    const name = territory.toLowerCase().replace(/[^a-z0-9]+/g, '-');
    return (
        <div className="grid gap-4 sm:grid-cols-3">
            {(Object.keys(DIMS) as CardSize[]).map((s) => {
                const svg = shareSvg(s, business, territory, group);
                return (
                    <div key={s} className="space-y-2">
                        <img
                            src={'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg)}
                            alt={`Share card: we claimed ${territory}`}
                            className="w-full rounded-md border border-line"
                        />
                        <Button variant="secondary" size="sm" className="w-full" onClick={() => void downloadPng(s, svg, name)}>
                            <Download /> {LABEL[s]}
                        </Button>
                    </div>
                );
            })}
        </div>
    );
}
