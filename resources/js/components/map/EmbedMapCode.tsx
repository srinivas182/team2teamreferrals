import { Code, Copy } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';

/** "Put this map on your website": copy-paste code for the embeddable map (public facts only). */
export function EmbedMapCode({ path, title }: { path: string; title: string }) {
    const [open, setOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const origin = typeof window === 'undefined' ? '' : window.location.origin;
    const code = `<iframe src="${origin}/embed/map/${path}" title="${title.replace(/"/g, '&quot;')} map" loading="lazy" style="width:100%;max-width:975px;aspect-ratio:975/720;border:0"></iframe>`;
    return (
        <div className="mt-2 text-sm">
            <button
                type="button"
                className="inline-flex items-center gap-1 font-medium text-primary"
                aria-expanded={open}
                onClick={() => setOpen(!open)}
            >
                <Code className="size-4" /> Put this map on your website
            </button>
            {open && (
                <div className="mt-2">
                    <pre className="overflow-x-auto rounded-md bg-bg p-3 text-xs break-all whitespace-pre-wrap">{code}</pre>
                    <Button
                        size="sm"
                        variant="secondary"
                        className="mt-2"
                        onClick={() => void navigator.clipboard.writeText(code).then(() => setCopied(true))}
                    >
                        <Copy /> {copied ? 'Copied' : 'Copy code'}
                    </Button>
                    <p className="mt-1 text-xs text-muted">It stays up to date by itself, and every link opens here.</p>
                </div>
            )}
        </div>
    );
}
