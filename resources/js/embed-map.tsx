import { createRoot } from 'react-dom/client';
import { NetworkMap } from '@/components/map/NetworkMap';
import type { MapData } from '@/components/map/types';

// The embeddable map page: reads its inline data, then keeps itself current. Links open on our site.
const el = document.getElementById('t2t-map');
const raw = document.getElementById('t2t-map-data')?.textContent;
if (el && raw) {
    const data = JSON.parse(raw) as MapData;
    const page = el.dataset.page ?? '';
    const base = el.dataset.base ?? '';
    createRoot(el).render(
        <div className="p-2">
            <NetworkMap initial={data} src={el.dataset.src ?? ''} base={base} external list={false} caption={false} />
            <p className="mt-1 text-xs">
                <a href={page} target="_blank" rel="noopener" className="text-primary">
                    {data.title} on Team to Team Referrals →
                </a>
            </p>
        </div>,
    );
    // Tell the page around us how tall we are (the widget loader sizes the frame).
    const report = () => parent.postMessage({ t2t: 1, h: document.body.scrollHeight }, '*');
    new ResizeObserver(report).observe(document.body);
}
