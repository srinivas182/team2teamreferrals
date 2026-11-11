import { router, useForm, usePage } from '@inertiajs/react';
import { Flag, Heart, Megaphone, PartyPopper, Pin, Search, Send, Sparkles } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { CityPicker, type CityOption } from '@/components/CityPicker';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { useGroupLive } from '@/lib/realtime';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Comment = { id: number; team: string; body: string; at: string; offer: boolean; sendUrl: string | null };
type Post = {
    id: number;
    kind: 'post' | 'ask' | 'announcement' | 'win' | 'event';
    eventId?: number | null;
    body: string;
    team: string;
    teamSlug: string | null;
    at: string;
    pinned: boolean;
    mine: boolean;
    city: string | null;
    reactions: { like: number; celebrate: number; thanks: number };
    myReaction: string | null;
    comments: Comment[];
};
type Props = {
    groups: { slug: string; name: string }[];
    group: { slug: string; name: string; isOwner: boolean } | null;
    posts: Post[];
    digest?: string;
    status?: string;
    seo: Seo;
};

const opts = { preserveScroll: true, preserveState: true } as const;

function PostCard({ p, isOwner }: { p: Post; isOwner: boolean }) {
    const [reply, setReply] = useState('');
    const react = (kind: string) => router.post(`/app/feed/posts/${p.id}/react`, { kind }, opts);
    return (
        <Card id={`post-${p.id}`} className="space-y-3 p-4 sm:p-5">
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <span className="font-semibold">{p.team}</span>
                {p.kind === 'ask' && <Badge tone="info">Asking for a pro{p.city ? ` in ${p.city}` : ''}</Badge>}
                {p.kind === 'announcement' && <Badge tone="sender">Announcement</Badge>}
                {p.kind === 'win' && <Badge tone="success">Win</Badge>}
                {p.kind === 'event' && <Badge tone="sender">Event</Badge>}
                {p.pinned && <Pin className="size-3.5 text-muted" aria-label="Pinned" />}
                <span className="ml-auto text-xs text-muted">{p.at}</span>
            </div>
            <p className="whitespace-pre-wrap">{p.body}</p>
            {p.kind === 'event' && p.eventId && (
                <div className="flex gap-2">
                    <Button size="sm" onClick={() => router.post(`/app/events/${p.eventId}/rsvp`, { status: 'going' }, opts)}>
                        I’m going
                    </Button>
                    <Button size="sm" variant="secondary" onClick={() => router.visit(`/app/events/${p.eventId}`)}>
                        Details
                    </Button>
                </div>
            )}
            <div className="flex flex-wrap items-center gap-1 text-sm">
                {(
                    [
                        ['like', Heart, 'Like'],
                        ['celebrate', PartyPopper, 'Celebrate'],
                        ['thanks', Sparkles, 'Thanks'],
                    ] as const
                ).map(([k, Icon, label]) => (
                    <button
                        key={k}
                        aria-pressed={p.myReaction === k}
                        aria-label={label}
                        onClick={() => react(k)}
                        className={`inline-flex h-9 items-center gap-1 rounded-full px-3 ${p.myReaction === k ? 'bg-primary/10 text-primary' : 'hover:bg-bg'}`}
                    >
                        <Icon className="size-4" aria-hidden /> {p.reactions[k] || ''}
                    </button>
                ))}
                {isOwner && (
                    <button
                        className="ml-auto h-9 rounded-full px-3 text-muted hover:bg-bg"
                        onClick={() => router.post(`/app/feed/posts/${p.id}/pin`, {}, opts)}
                    >
                        {p.pinned ? 'Unpin' : 'Pin'}
                    </button>
                )}
                {!p.mine && (
                    <button
                        aria-label="Report"
                        className={`h-9 rounded-full px-3 text-muted hover:bg-bg ${isOwner ? '' : 'ml-auto'}`}
                        onClick={() =>
                            window.confirm('Report this post to the network owner?') && router.post(`/app/feed/report/post/${p.id}`, {}, opts)
                        }
                    >
                        <Flag className="size-4" />
                    </button>
                )}
            </div>
            {p.comments.length > 0 && (
                <ul className="space-y-2 border-t border-line pt-3 text-sm">
                    {p.comments.map((c) => (
                        <li key={c.id}>
                            <span className="font-medium">{c.team}</span> {c.offer && <Badge tone="success">Covers this city</Badge>}{' '}
                            <span className="whitespace-pre-wrap">{c.body}</span>
                            <div className="mt-1 flex items-center gap-2">
                                {c.sendUrl && (
                                    <Button size="sm" onClick={() => router.visit(c.sendUrl as string)}>
                                        <Send /> Send the referral
                                    </Button>
                                )}
                                <button
                                    aria-label="Report comment"
                                    className="text-xs text-muted"
                                    onClick={() =>
                                        window.confirm('Report this comment?') && router.post(`/app/feed/report/comment/${c.id}`, {}, opts)
                                    }
                                >
                                    Report
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <div className="flex gap-2">
                <Input
                    aria-label="Reply"
                    placeholder={p.kind === 'ask' ? 'Cover that city? Say so here' : 'Reply… (use @team-name to mention)'}
                    value={reply}
                    onChange={(e) => setReply(e.target.value)}
                />
                <Button
                    size="sm"
                    variant="secondary"
                    disabled={!reply.trim()}
                    onClick={() => router.post(`/app/feed/posts/${p.id}/comments`, { body: reply }, { ...opts, onSuccess: () => setReply('') })}
                >
                    Reply
                </Button>
            </div>
        </Card>
    );
}

/** Portal → Feed: the network's posts, asks, announcements and wins. Mobile first, endless scroll, live updates. */
export default function Feed({ groups, group, posts: first, digest = 'weekly', status, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [posts, setPosts] = useState<Post[]>(first);
    const [done, setDone] = useState(first.length < 20);
    const [fresh, setFresh] = useState(false);
    const sentinel = useRef<HTMLDivElement>(null);
    const form = useForm<{ kind: string; body: string; city_id: number | null }>({ kind: 'post', body: '', city_id: null });
    // A fresh first page (after posting or "new posts") replaces the list; done during render, as React recommends.
    const [prevFirst, setPrevFirst] = useState(first);
    if (first !== prevFirst) {
        setPrevFirst(first);
        setPosts(first);
        setDone(first.length < 20);
    }

    // Endless scroll: fetch older posts when the end of the list comes into view.
    useEffect(() => {
        if (!group || done || !sentinel.current) return;
        const io = new IntersectionObserver(async ([e]) => {
            const last = posts[posts.length - 1];
            if (!e?.isIntersecting || !last) return;
            const res = await fetch(`/app/feed/${group.slug}/more?before=${last.id}`, { headers: { Accept: 'application/json' } });
            const json = (await res.json()) as { posts: Post[] };
            setPosts((cur) => [...cur, ...json.posts.filter((n) => !cur.some((c) => c.id === n.id))]);
            if (json.posts.length < 20) setDone(true);
        });
        io.observe(sentinel.current);
        return () => io.disconnect();
    }, [group, posts, done]);

    const onLive = useCallback(() => setFresh(true), []);
    useGroupLive(group?.slug ?? null, onLive);

    return (
        <PortalLayout title="Feed" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-2xl space-y-4">
                {!group && <Card className="p-6 text-muted">Once you hold a seat, your network’s feed appears here.</Card>}
                {group && (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            {groups.length > 1 ? (
                                <select
                                    aria-label="Network"
                                    className="h-10 rounded-md border border-line bg-surface px-2"
                                    value={group.slug}
                                    onChange={(e) => router.visit(`/app/feed/${e.target.value}`)}
                                >
                                    {groups.map((g) => (
                                        <option key={g.slug} value={g.slug}>
                                            {g.name}
                                        </option>
                                    ))}
                                </select>
                            ) : (
                                <h2 className="font-semibold">{group.name}</h2>
                            )}
                            <label className="ml-auto flex items-center gap-2 text-sm text-muted">
                                Digest
                                <select
                                    aria-label="Digest"
                                    className="h-9 rounded-md border border-line bg-surface px-2"
                                    value={digest}
                                    onChange={(e) => router.post('/app/feed/digest', { digest: e.target.value }, opts)}
                                >
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="off">Off</option>
                                </select>
                            </label>
                            {group.isOwner && (
                                <a href={`/app/group/${group.slug}/moderation`} className="text-sm font-medium text-primary">
                                    Moderation
                                </a>
                            )}
                        </div>
                        {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                        <Card className="space-y-3 p-4">
                            <div className="flex flex-wrap gap-1" role="tablist">
                                {(
                                    [
                                        ['post', 'Post', null],
                                        ['ask', 'Ask the network', Search],
                                        ...(group.isOwner ? [['announcement', 'Announcement', Megaphone]] : []),
                                    ] as [string, string, typeof Search | null][]
                                ).map(([k, label, Icon]) => (
                                    <button
                                        key={k}
                                        role="tab"
                                        aria-selected={form.data.kind === k}
                                        onClick={() => form.setData('kind', k)}
                                        className={`inline-flex h-9 items-center gap-1 rounded-full px-3 text-sm ${form.data.kind === k ? 'bg-primary text-white' : 'bg-bg'}`}
                                    >
                                        {Icon && <Icon className="size-4" aria-hidden />} {label}
                                    </button>
                                ))}
                            </div>
                            {form.data.kind === 'ask' && (
                                <CityPicker
                                    placeholder="Which city do you need a pro in?"
                                    onPick={(c: CityOption) => form.setData('city_id', c.id)}
                                />
                            )}
                            <textarea
                                aria-label="Write a post"
                                rows={3}
                                className="w-full rounded-md border border-line bg-surface p-3"
                                placeholder={
                                    form.data.kind === 'ask'
                                        ? 'Anyone have a great pro in this city? Describe what your client needs (no names or phone numbers).'
                                        : 'Share something with your network…'
                                }
                                value={form.data.body}
                                onChange={(e) => form.setData('body', e.target.value)}
                            />
                            {Object.values(form.errors)[0] && <p className="text-sm text-danger">{Object.values(form.errors)[0]}</p>}
                            <div className="flex items-center justify-between">
                                <p className="text-xs text-muted">Phone numbers and emails are hidden automatically.</p>
                                <Button
                                    disabled={!form.data.body.trim()}
                                    loading={form.processing}
                                    onClick={() => form.post(`/app/feed/${group.slug}/posts`, { ...opts, onSuccess: () => form.reset() })}
                                >
                                    Post
                                </Button>
                            </div>
                        </Card>
                        {fresh && (
                            <button
                                className="sticky top-2 z-10 mx-auto block rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white shadow"
                                onClick={() => (setFresh(false), router.reload({ only: ['posts'] }))}
                            >
                                New posts: tap to see them
                            </button>
                        )}
                        {posts.length === 0 && <Card className="p-6 text-center text-muted">No posts yet. Say hello to your network!</Card>}
                        {posts.map((p) => (
                            <PostCard key={p.id} p={p} isOwner={group.isOwner} />
                        ))}
                        {!done && <div ref={sentinel} className="h-10" aria-hidden />}
                    </>
                )}
            </div>
        </PortalLayout>
    );
}
