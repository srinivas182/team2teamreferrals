import { router, usePage, usePoll } from '@inertiajs/react';
import Echo from 'laravel-echo';
import { useEffect, useRef } from 'react';

type Realtime = { team: string | null } | null;
let echo: Echo<'mercure'> | null = null;

/** One connection to the Mercure hub (FrankenPHP), created in the browser only (never during server rendering). */
function connection(): Echo<'mercure'> | null {
    if (typeof window === 'undefined') return null;
    if (echo) return echo;
    const xsrf = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
    echo = new Echo({
        broadcaster: 'mercure',
        host: '/.well-known/mercure',
        authEndpoint: '/broadcasting/auth',
        auth: { headers: { 'X-XSRF-TOKEN': xsrf } },
    });
    return echo;
}

/**
 * Live updates for one referral: reloads the given props when anything changes, and passes typing indicators
 * between the two teams. Falls back to a 10-second refresh when real-time is off.
 */
export function useReferralLive(ulid: string, only: string[], onTyping: (who: string) => void) {
    const { realtime } = usePage<{ realtime: Realtime }>().props;
    const poll = usePoll(10000, { only }, { autoStart: false });
    const typingRef = useRef(onTyping);
    useEffect(() => {
        typingRef.current = onTyping;
    }, [onTyping]);
    useEffect(() => {
        const e = realtime ? connection() : null;
        if (!e) {
            poll.start();
            return () => poll.stop();
        }
        const ch = e.private(`referrals.${ulid}`);
        ch.listen('.referral.changed', () => router.reload({ only }));
        ch.listenForWhisper('typing', (d: { who: string }) => typingRef.current(d.who));
        return () => e.leave(`referrals.${ulid}`);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ulid, realtime]);
    return (who: string) => {
        if (realtime) connection()?.private(`referrals.${ulid}`).whisper('typing', { who });
    };
}

/** Keeps the Inbox/Sent badges and the bell current when anything changes on the team's referrals. */
export function useTeamLive() {
    const { realtime } = usePage<{ realtime: Realtime }>().props;
    useEffect(() => {
        const team = realtime?.team;
        const e = team ? connection() : null;
        if (!e || !team) return;
        e.private(`teams.${team}`).listen('.referral.changed', () => router.reload({ only: ['referralUnread', 'notifications'] }));
        return () => e.leave(`teams.${team}`);
    }, [realtime?.team]);
}

/**
 * A network's feed: when someone posts or comments, show "new posts" without reloading what the reader is looking at.
 * Falls back to a 30-second check when real-time is off.
 */
export function useGroupLive(group: string | null, onChange: () => void) {
    useEffect(() => {
        if (!group) return;
        const e = connection();
        if (e) {
            e.private(`groups.${group}`).listen('.feed.changed', onChange);
            return () => e.leave(`groups.${group}`);
        }
        const t = window.setInterval(onChange, 30000);
        return () => window.clearInterval(t);
    }, [group, onChange]);
}
