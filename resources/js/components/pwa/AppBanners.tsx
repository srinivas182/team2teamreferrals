import { Link, usePage } from '@inertiajs/react';
import { Download, RefreshCw, Share, Smartphone, WifiOff, X } from 'lucide-react';
import { useEffect, useState, useSyncExternalStore } from 'react';
import { Button } from '@/components/ui/button';
import { pushSupported } from '@/lib/push';
import { applyUpdate, canPromptInstall, ios, onPwaChange, promptInstall, registerServiceWorker, standalone, updateWaiting } from '@/lib/pwa';

const DISMISS_KEY = 't2t-install-dismissed';
const PUSH_KEY = 't2t-push-nudge-dismissed';
const REASK_AFTER_DAYS = 30;

function dismissedRecently(key: string): boolean {
    try {
        const at = Number(localStorage.getItem(key));
        return at > 0 && Date.now() - at < REASK_AFTER_DAYS * 86_400_000;
    } catch {
        return false;
    }
}

function remember(key: string) {
    try {
        localStorage.setItem(key, String(Date.now()));
    } catch {
        // private mode: it'll ask again next time
    }
}

const subscribeOnline = (fn: () => void) => {
    window.addEventListener('online', fn);
    window.addEventListener('offline', fn);
    return () => {
        window.removeEventListener('online', fn);
        window.removeEventListener('offline', fn);
    };
};

/**
 * The installable app's notices in the member area (Sprint 53b), at the top of the page, never covering anything:
 * - offline: what still works
 * - a new version is ready: refresh when you're ready
 * - after your first referral: install the app (the browser's own prompt, or the steps on iPhone and iPad)
 * - opened from the home screen: turn on notifications
 */
export function AppBanners() {
    const { version, props } = usePage<{ pwa?: { sent: boolean } | null }>();
    const online = useSyncExternalStore(
        subscribeOnline,
        () => navigator.onLine,
        () => true,
    );
    const [, rerender] = useState(0);
    const [ready, setReady] = useState(false);
    const [steps, setSteps] = useState(false);
    const [hidden, setHidden] = useState<string[]>([]);

    useEffect(() => onPwaChange(() => rerender((n) => n + 1)), []);
    useEffect(() => {
        // After the page has settled, so it never slows the first view.
        const id = window.setTimeout(() => {
            void registerServiceWorker(version);
            setReady(true);
        }, 1500);
        return () => window.clearTimeout(id);
    }, [version]);

    if (!ready) return null;
    const hide = (key: string) => {
        remember(key);
        setHidden((h) => [...h, key]);
    };
    const installable =
        !!props.pwa?.sent && !standalone() && (canPromptInstall() || ios()) && !dismissedRecently(DISMISS_KEY) && !hidden.includes(DISMISS_KEY);
    const pushNudge =
        standalone() && pushSupported() && Notification.permission === 'default' && !dismissedRecently(PUSH_KEY) && !hidden.includes(PUSH_KEY);

    return (
        <div className="mb-4 space-y-3 empty:hidden" data-pwa-banners>
            {!online && (
                <div role="status" className="flex items-start gap-3 rounded-lg border border-line bg-surface p-3 text-sm">
                    <WifiOff className="mt-0.5 size-4 shrink-0 text-muted" aria-hidden />
                    <p>You’re offline. Pages you’ve opened recently still work; sending or changing anything needs a connection.</p>
                </div>
            )}
            {updateWaiting() && (
                <div role="status" className="flex flex-wrap items-center gap-3 rounded-lg border border-line bg-surface p-3 text-sm">
                    <RefreshCw className="size-4 shrink-0 text-primary" aria-hidden />
                    <p className="flex-1">A new version of Team to Team is ready.</p>
                    <Button size="sm" onClick={applyUpdate}>
                        Refresh now
                    </Button>
                </div>
            )}
            {installable && (
                <section aria-labelledby="install-title" className="rounded-lg border border-line bg-surface p-4 text-sm">
                    <div className="flex items-start gap-3">
                        <Smartphone className="mt-0.5 size-5 shrink-0 text-primary" aria-hidden />
                        <div className="flex-1">
                            <h2 id="install-title" className="font-semibold">
                                Put Team to Team on your home screen
                            </h2>
                            <p className="mt-1 text-muted">
                                Open referrals in one tap and get notified the moment one arrives. It takes a few seconds.
                            </p>
                            {steps && (
                                <ol className="mt-3 list-decimal space-y-1 pl-5">
                                    <li>
                                        Tap the Share button{' '}
                                        <Share className="inline size-4 align-text-bottom" aria-label="(the square with an arrow)" /> in Safari’s
                                        toolbar.
                                    </li>
                                    <li>Choose “Add to Home Screen”, then “Add”.</li>
                                    <li>Open Team to Team from your home screen and sign in once.</li>
                                </ol>
                            )}
                            <div className="mt-3 flex flex-wrap gap-2">
                                {canPromptInstall() ? (
                                    <Button size="sm" onClick={() => void promptInstall().then((ok) => ok && hide(DISMISS_KEY))}>
                                        <Download className="size-4" aria-hidden /> Install the app
                                    </Button>
                                ) : (
                                    !steps && (
                                        <Button size="sm" onClick={() => setSteps(true)}>
                                            Show me how
                                        </Button>
                                    )
                                )}
                                <Button size="sm" variant="ghost" onClick={() => hide(DISMISS_KEY)}>
                                    Not now
                                </Button>
                            </div>
                        </div>
                        <button className="text-muted hover:text-ink" onClick={() => hide(DISMISS_KEY)} aria-label="Close">
                            <X className="size-4" />
                        </button>
                    </div>
                </section>
            )}
            {pushNudge && (
                <div className="flex flex-wrap items-center gap-3 rounded-lg border border-line bg-surface p-3 text-sm">
                    <p className="flex-1">Get a notification the moment a referral arrives.</p>
                    <Button size="sm" asChild>
                        <Link href="/app/settings/notifications" onClick={() => hide(PUSH_KEY)}>
                            Turn on notifications
                        </Link>
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => hide(PUSH_KEY)}>
                        Not now
                    </Button>
                </div>
            )}
        </div>
    );
}
