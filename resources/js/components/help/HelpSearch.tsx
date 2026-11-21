import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/field';

/** Search the help centre. */
export function HelpSearch({ initial = '', large = false }: { initial?: string; large?: boolean }) {
    const [q, setQ] = useState(initial);
    return (
        <form
            role="search"
            className="flex gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                if (q.trim()) router.get('/help/search', { q: q.trim() });
            }}
        >
            <label htmlFor="help-q" className="sr-only">
                Search help
            </label>
            <Input
                id="help-q"
                type="search"
                placeholder="Search help, e.g. “claim a seat”"
                value={q}
                onChange={(e) => setQ(e.target.value)}
                className={large ? 'h-12 text-base' : ''}
            />
            <Button type="submit" size={large ? 'lg' : 'md'}>
                <Search /> Search
            </Button>
        </form>
    );
}

/** "Still need help?" at the foot of help pages. */
export function StillNeedHelp({ article, q }: { article?: string; q?: string }) {
    const qs = article ? `?article=${encodeURIComponent(article)}` : q ? `?q=${encodeURIComponent(q)}` : '';
    return (
        <section className="mt-10 rounded-2xl border border-line bg-surface p-6 shadow-sm">
            <h2 className="font-display text-xl font-bold">Still need help?</h2>
            <p className="mt-1">Send us a message and a person replies, usually within a business day.</p>
            <Button asChild className="mt-3">
                <a href={`/support/new${qs}`}>Contact support</a>
            </Button>
        </section>
    );
}
