import { ExternalLink, RotateCcw, Send, ThumbsDown, ThumbsUp, UserRound, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { AllyAvatar } from '@/components/ally/AllyAvatar';
import { Button } from '@/components/ui/button';
import { Input, Textarea } from '@/components/ui/field';
import { xsrf } from '@/lib/xsrf';

type Msg = {
    id: number | null;
    role: 'user' | 'ally';
    body: string;
    sources: { title: string; href: string }[];
    actions: { label: string; href: string }[];
    answered: boolean | null;
    helpful: boolean | null;
};
type State = { enabled: boolean; signedIn?: boolean; audience?: string; messages?: Msg[]; ticket?: string | null };

const call = async <T,>(url: string, body?: object): Promise<{ ok: boolean; status: number; data: T }> => {
    const r = await fetch(url, {
        method: body ? 'POST' : 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() } : {}) },
        body: body ? JSON.stringify(body) : undefined,
    });
    return { ok: r.ok, status: r.status, data: (await r.json().catch(() => ({}))) as T };
};

const STARTERS: Record<string, string[]> = {
    visitor: ['How does a referral network work?', 'How do I claim a seat in my city?', 'How do I advertise my business?'],
    partner: ['When will my card be charged?', 'How do I add another city?', 'Why am I on a waitlist?'],
    member: ['Do I have referrals waiting?', 'When is my next bill?', 'How do referral fees work?'],
};

/** The Ally chat window. */
export default function AllyPanel({ open, onClose }: { open: boolean; onClose: () => void }) {
    const [state, setState] = useState<State | null>(null);
    const [messages, setMessages] = useState<Msg[]>([]);
    const [q, setQ] = useState('');
    const [busy, setBusy] = useState(false);
    const [offerPerson, setOfferPerson] = useState(false);
    const [handoff, setHandoff] = useState<'closed' | 'form' | 'sent'>('closed');
    const [who, setWho] = useState({ name: '', email: '', note: '' });
    const [ticket, setTicket] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const input = useRef<HTMLTextAreaElement>(null);
    const end = useRef<HTMLDivElement>(null);

    useEffect(() => {
        void call<State>('/ally/state').then(({ data }) => {
            setState(data);
            setMessages(data.messages ?? []);
            setTicket(data.ticket ?? null);
        });
    }, []);
    useEffect(() => {
        if (open) input.current?.focus();
    }, [open, state]);
    useEffect(() => {
        end.current?.scrollIntoView({ block: 'end' });
    }, [messages, busy, handoff]);
    useEffect(() => {
        const esc = (e: KeyboardEvent) => e.key === 'Escape' && open && onClose();
        window.addEventListener('keydown', esc);
        return () => window.removeEventListener('keydown', esc);
    }, [open, onClose]);

    const ask = async (text: string) => {
        const question = text.trim();
        if (!question || busy) return;
        setError(null);
        setQ('');
        setMessages((m) => [...m, { id: null, role: 'user', body: question, sources: [], actions: [], answered: null, helpful: null }]);
        setBusy(true);
        const r = await call<{ message: Msg; offerPerson: boolean }>('/ally/ask', { q: question, page: window.location.pathname }).catch(() => null);
        setBusy(false);
        if (!r || (!r.ok && r.status !== 429) || !r.data.message) {
            setError('Something went wrong. Please try again, or talk to a person.');
            setOfferPerson(true);
            return;
        }
        setMessages((m) => [...m, r.data.message]);
        setOfferPerson(r.data.offerPerson);
    };
    const rate = (id: number, helpful: boolean) => {
        setMessages((m) => m.map((x) => (x.id === id ? { ...x, helpful } : x)));
        void call('/ally/feedback', { id, helpful });
        if (!helpful) setOfferPerson(true);
    };
    const sendHandoff = async () => {
        setError(null);
        const r = await call<{ ticket?: string; message?: string; errors?: Record<string, string[]> }>('/ally/handoff', who);
        if (!r.ok) {
            setError(
                r.data.errors ? (Object.values(r.data.errors)[0]?.[0] ?? 'Please check your details.') : (r.data.message ?? 'Please try again.'),
            );
            return;
        }
        setTicket(r.data.ticket ?? null);
        setHandoff('sent');
    };
    const restart = () => {
        void call('/ally/reset', {});
        setMessages([]);
        setTicket(null);
        setHandoff('closed');
        setOfferPerson(false);
    };

    if (!open) return null;
    const audience = state?.audience === 'partner' ? 'partner' : state?.signedIn ? 'member' : 'visitor';
    return (
        <section
            role="dialog"
            aria-label="Ally, your AI assistant"
            className="fixed right-0 bottom-0 z-50 flex h-[min(640px,100dvh)] w-full flex-col border border-line bg-bg shadow-2xl sm:right-4 sm:bottom-4 sm:w-[400px] sm:rounded-2xl print:hidden"
        >
            <header className="flex items-center gap-3 border-b border-line px-4 py-3">
                <AllyAvatar size={40} />
                <div className="flex-1 leading-tight">
                    <h2 className="font-semibold text-ink">Ally</h2>
                    <p className="text-xs text-muted">Your AI Assistant</p>
                </div>
                {messages.length > 0 && (
                    <Button variant="ghost" size="icon" aria-label="Start a new chat" onClick={restart}>
                        <RotateCcw />
                    </Button>
                )}
                <Button variant="ghost" size="icon" aria-label="Close Ally" onClick={onClose}>
                    <X />
                </Button>
            </header>
            <div className="flex-1 space-y-3 overflow-y-auto px-4 py-3" aria-live="polite">
                {state && !state.enabled ? (
                    <p className="text-sm">
                        Ally isn’t available right now.{' '}
                        <a href="/support/new" className="text-primary">
                            Contact support
                        </a>{' '}
                        and a person will help.
                    </p>
                ) : (
                    <>
                        <div className="rounded-2xl rounded-tl-sm bg-surface p-3 text-sm">
                            Hi, I’m Ally. Ask me anything about{' '}
                            {audience === 'partner'
                                ? 'your listing'
                                : audience === 'member'
                                  ? 'your account, referrals or billing'
                                  : 'how the network works'}
                            . If I can’t help, I’ll pass you to a person.
                        </div>
                        {messages.length === 0 && (
                            <div className="flex flex-wrap gap-2">
                                {(STARTERS[audience] ?? []).map((s) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => void ask(s)}
                                        className="rounded-full border border-line px-3 py-1 text-left text-xs hover:bg-surface"
                                    >
                                        {s}
                                    </button>
                                ))}
                            </div>
                        )}
                        {messages.map((m, i) =>
                            m.role === 'user' ? (
                                <div key={i} className="ml-8 rounded-2xl rounded-tr-sm bg-primary p-3 text-sm whitespace-pre-line text-on-primary">
                                    {m.body}
                                </div>
                            ) : (
                                <div key={i} className="mr-4 space-y-2">
                                    <div className="rounded-2xl rounded-tl-sm bg-surface p-3 text-sm whitespace-pre-line">{m.body}</div>
                                    {m.sources.length > 0 && (
                                        <ul className="space-y-1 pl-1 text-xs">
                                            {m.sources.map((s) => (
                                                <li key={s.href}>
                                                    <a href={s.href} className="inline-flex items-center gap-1 text-primary">
                                                        <ExternalLink className="size-3" aria-hidden /> {s.title}
                                                    </a>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {m.actions.length > 0 && (
                                        <div className="flex flex-wrap gap-2">
                                            {m.actions.map((a) => (
                                                <Button key={a.href} variant="secondary" size="sm" asChild>
                                                    <a href={a.href}>{a.label}</a>
                                                </Button>
                                            ))}
                                        </div>
                                    )}
                                    {m.id !== null && (
                                        <div className="flex items-center gap-1 text-xs text-muted">
                                            {m.helpful === null ? (
                                                <>
                                                    <span>Helpful?</span>
                                                    <button
                                                        type="button"
                                                        aria-label="Yes, helpful"
                                                        className="rounded p-1 hover:bg-surface"
                                                        onClick={() => rate(m.id!, true)}
                                                    >
                                                        <ThumbsUp className="size-3.5" aria-hidden />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        aria-label="No, not helpful"
                                                        className="rounded p-1 hover:bg-surface"
                                                        onClick={() => rate(m.id!, false)}
                                                    >
                                                        <ThumbsDown className="size-3.5" aria-hidden />
                                                    </button>
                                                </>
                                            ) : (
                                                <span>Thanks for the feedback.</span>
                                            )}
                                        </div>
                                    )}
                                </div>
                            ),
                        )}
                        {busy && (
                            <div className="mr-4 rounded-2xl rounded-tl-sm bg-surface p-3 text-sm text-muted" role="status">
                                Ally is thinking…
                            </div>
                        )}
                        {error && <p className="text-sm text-danger">{error}</p>}
                        {handoff === 'form' && (
                            <div className="space-y-2 rounded-xl border border-line p-3 text-sm">
                                <p className="font-medium">We’ll pass this conversation to a person.</p>
                                {!state?.signedIn && (
                                    <>
                                        <Input
                                            aria-label="Your name"
                                            placeholder="Your name"
                                            value={who.name}
                                            onChange={(e) => setWho({ ...who, name: e.target.value })}
                                        />
                                        <Input
                                            aria-label="Your email"
                                            type="email"
                                            placeholder="Your email"
                                            value={who.email}
                                            onChange={(e) => setWho({ ...who, email: e.target.value })}
                                        />
                                    </>
                                )}
                                <Textarea
                                    aria-label="Anything to add (optional)"
                                    placeholder="Anything to add? (optional)"
                                    rows={2}
                                    value={who.note}
                                    onChange={(e) => setWho({ ...who, note: e.target.value })}
                                />
                                <Button size="sm" onClick={() => void sendHandoff()}>
                                    Send to support
                                </Button>
                            </div>
                        )}
                        {(handoff === 'sent' || ticket) && (
                            <p className="rounded-xl border border-line p-3 text-sm">
                                Passed to our team as {ticket}. We’ll reply by email{state?.signedIn ? ' and under Help and support' : ''}, usually
                                within a business day.
                            </p>
                        )}
                        <div ref={end} />
                    </>
                )}
            </div>
            {state?.enabled && (
                <footer className="border-t border-line px-4 py-3">
                    {handoff === 'closed' && !ticket && messages.length > 0 && (
                        <button
                            type="button"
                            onClick={() => setHandoff('form')}
                            className={`mb-2 inline-flex items-center gap-1 text-xs ${offerPerson ? 'font-semibold text-primary' : 'text-muted'}`}
                        >
                            <UserRound className="size-3.5" aria-hidden /> Talk to a person
                        </button>
                    )}
                    <form
                        className="flex items-end gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void ask(q);
                        }}
                    >
                        <label htmlFor="ally-q" className="sr-only">
                            Ask Ally
                        </label>
                        <Textarea
                            id="ally-q"
                            ref={input}
                            rows={1}
                            maxLength={1000}
                            placeholder="Ask a question…"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && !e.shiftKey) {
                                    e.preventDefault();
                                    void ask(q);
                                }
                            }}
                            className="max-h-32 min-h-10 resize-none py-2"
                        />
                        <Button type="submit" size="icon" aria-label="Send" disabled={busy || !q.trim()}>
                            <Send />
                        </Button>
                    </form>
                    <p className="mt-2 text-[11px] text-muted">
                        Ally answers from our help centre{state?.signedIn ? ' and your own account' : ''}. It can make mistakes; nothing it says is
                        legal or financial advice.
                    </p>
                </footer>
            )}
        </section>
    );
}
