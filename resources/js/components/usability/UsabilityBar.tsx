import { router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, ClipboardCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { xsrf } from '@/lib/xsrf';

type Task = { key: string; title: string; task: string; done: string; start: string };
type State = { active: boolean; tasks: Task[]; current: number; results: Record<string, string | null> };
type Event = { kind: string; path?: string; detail?: string; seconds?: number; task?: string | null };

/** A tester counts as stuck after this long on one page without finishing the task. */
const STUCK_AFTER_MS = 90_000;
/** Three clicks this close together, in about the same place, are clicks that did nothing. */
const RAGE_CLICKS = 3;
const RAGE_WINDOW_MS = 800;
const RAGE_RADIUS_PX = 40;

// Kept outside the component: pages each render their own layout, so the bar remounts on every visit, but the
// recording carries on across them.
let cached: State | null = null;
let installed = false;
let task: string | null = null;
let pagePath = '';
let pageStart = 0;
let stuckTimer: number | undefined;
let clicks: { t: number; x: number; y: number }[] = [];

function send(events: Event[]) {
    const body = JSON.stringify({ events: events.map((e) => ({ path: window.location.pathname, task, ...e })) });
    try {
        if (navigator.sendBeacon('/usability/events', new Blob([body], { type: 'application/json' }))) return;
    } catch {
        // fall through to fetch
    }
    void fetch('/usability/events', { method: 'POST', body, keepalive: true, headers: { 'Content-Type': 'application/json' } }).catch(
        () => undefined,
    );
}

function seconds(since: number) {
    return Math.max(0, Math.round((Date.now() - since) / 1000));
}

function armStuck() {
    window.clearTimeout(stuckTimer);
    stuckTimer = window.setTimeout(
        () => send([{ kind: 'stuck', seconds: STUCK_AFTER_MS / 1000, detail: 'No progress for 90 seconds' }]),
        STUCK_AFTER_MS,
    );
}

function leavePage() {
    if (pagePath !== '') send([{ kind: 'page', path: pagePath, seconds: seconds(pageStart) }]);
}

/** What a click landed on, in words: its label or text, never anything typed into a field. */
function describe(el: Element | null): string {
    const target = el?.closest('button, a, [role="button"], label, input, select, textarea, [data-tour]') ?? el;
    if (!target) return 'the page';
    const label =
        target.getAttribute('aria-label') ??
        target.getAttribute('data-tour') ??
        (target instanceof HTMLInputElement ? target.name : (target.textContent ?? ''));
    return `${target.tagName.toLowerCase()} “${label.replace(/\s+/g, ' ').trim().slice(0, 60)}”`;
}

function install() {
    if (installed) return;
    installed = true;
    pagePath = window.location.pathname;
    pageStart = Date.now();
    armStuck();
    document.addEventListener('inertia:navigate', () => {
        if (window.location.pathname === pagePath) return;
        leavePage();
        pagePath = window.location.pathname;
        pageStart = Date.now();
        armStuck();
    });
    document.addEventListener('inertia:error', (e) => {
        const errors = (e.detail as { errors?: Record<string, unknown> }).errors ?? {};
        const fields = Object.keys(errors);
        if (fields.length) send([{ kind: 'error', detail: `Errors in: ${fields.join(', ')}` }]);
    });
    document.addEventListener(
        'click',
        (e) => {
            const el = e.target instanceof Element ? e.target : null;
            if (el?.closest('[data-usability]')) return;
            const now = Date.now();
            clicks = [
                ...clicks.filter((c) => now - c.t < RAGE_WINDOW_MS && Math.hypot(c.x - e.clientX, c.y - e.clientY) < RAGE_RADIUS_PX),
                { t: now, x: e.clientX, y: e.clientY },
            ];
            if (clicks.length >= RAGE_CLICKS) {
                clicks = [];
                send([{ kind: 'rage', detail: `Clicked ${describe(el)} again and again` }]);
            }
        },
        true,
    );
    window.addEventListener('pagehide', leavePage);
}

function taskStart(key: string) {
    const k = `t2t_ut_${key}`;
    try {
        const v = Number(sessionStorage.getItem(k));
        if (v > 0) return v;
        sessionStorage.setItem(k, String(Date.now()));
    } catch {
        // private mode: time from now
    }
    return Date.now();
}

/** The usability-test bar: the task the tester is on, and buttons to say they've done it, are stuck or are giving up. */
export default function UsabilityBar() {
    const [state, setState] = useState<State | null>(() => cached);
    const [open, setOpen] = useState(true);
    const [asking, setAsking] = useState<'help' | 'gave_up' | null>(null);
    const [note, setNote] = useState('');
    const [sent, setSent] = useState(false);

    useEffect(() => {
        let live = true;
        fetch('/usability/state', { headers: { Accept: 'application/json' } })
            .then((r) => r.json() as Promise<State>)
            .then((s) => {
                cached = s;
                if (!live) return;
                setState(s);
                if (s.active) install();
            })
            .catch(() => undefined);
        return () => {
            live = false;
        };
    }, []);

    const current = state?.active ? (state.tasks[state.current] ?? null) : null;
    useEffect(() => {
        task = current?.key ?? null;
        if (current) taskStart(current.key); // the clock for this task starts when it's first shown
    }, [current]);

    if (!state?.active) return null;

    const finish = async (kind: 'done' | 'gave_up', detail: string) => {
        if (!current) return;
        const r = await fetch('/usability/events', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({
                events: [{ kind, task: current.key, path: window.location.pathname, seconds: seconds(taskStart(current.key)), detail }],
            }),
        });
        const next = (await r.json()) as { current: number; finished: boolean };
        const s = { ...state, current: next.current, results: { ...state.results, [current.key]: kind } };
        cached = s;
        setState(s);
        setAsking(null);
        setNote('');
        setSent(false);
        armStuck();
    };

    return (
        <aside
            data-usability
            aria-label="Usability test"
            className="fixed bottom-4 left-4 z-50 w-[calc(100%-2rem)] max-w-sm rounded-lg border border-line bg-surface p-4 text-sm shadow-lg"
        >
            <div className="flex items-center gap-2">
                <ClipboardCheck className="size-4 text-primary" aria-hidden />
                <span className="font-semibold">{current ? `Test task ${state.current + 1} of ${state.tasks.length}` : 'Test finished'}</span>
                <button
                    className="ml-auto text-muted hover:text-ink"
                    onClick={() => setOpen(!open)}
                    aria-expanded={open}
                    aria-label={open ? 'Hide the task' : 'Show the task'}
                >
                    {open ? <ChevronDown className="size-4" /> : <ChevronUp className="size-4" />}
                </button>
            </div>
            {open && current && (
                <div className="mt-2 space-y-2">
                    <p className="font-semibold">{current.title}</p>
                    <p>{current.task}</p>
                    <p className="text-xs text-muted">{current.done}</p>
                    {window.location.pathname !== current.start && state.results[current.key] === undefined && (
                        <a href={current.start} className="block text-xs text-primary">
                            Start from the beginning
                        </a>
                    )}
                    {asking ? (
                        <div className="space-y-2">
                            <label className="block text-xs font-medium" htmlFor="ut-note">
                                {asking === 'help' ? 'What were you trying to do? (optional)' : 'What stopped you? (optional)'}
                            </label>
                            <textarea
                                id="ut-note"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                maxLength={500}
                                rows={2}
                                className="w-full rounded-md border border-line bg-bg p-2"
                            />
                            <div className="flex gap-2">
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        if (asking === 'help') {
                                            send([{ kind: 'help', detail: note || 'Pressed “I’m stuck”' }]);
                                            setAsking(null);
                                            setNote('');
                                            setSent(true);
                                        } else {
                                            void finish('gave_up', note);
                                        }
                                    }}
                                >
                                    {asking === 'help' ? 'Send' : 'Give up and move on'}
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => setAsking(null)}>
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-wrap gap-2 pt-1">
                            <Button size="sm" onClick={() => void finish('done', '')}>
                                I’ve done it
                            </Button>
                            <Button size="sm" variant="secondary" onClick={() => setAsking('help')}>
                                I’m stuck
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => setAsking('gave_up')}>
                                Give up
                            </Button>
                        </div>
                    )}
                    {sent && <p className="text-xs text-muted">Thanks, noted. Keep trying, or give up to move on: both help us.</p>}
                </div>
            )}
            {open && !current && <p className="mt-2">That’s every task. Thank you: what you found will make the product better for everyone.</p>}
            {open && (
                <button className="mt-3 text-xs text-muted underline" onClick={() => router.post('/usability/stop')}>
                    {current ? 'Stop the test' : 'Close'}
                </button>
            )}
        </aside>
    );
}
