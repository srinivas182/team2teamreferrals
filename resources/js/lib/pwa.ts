/**
 * The installable app (Sprint 53b): registering the service worker, knowing whether we're running from the home
 * screen, the browser's install prompt, and a new version waiting to take over.
 */

type InstallPrompt = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> };

let deferred: InstallPrompt | null = null;
let waiting: ServiceWorker | null = null;
const listeners = new Set<() => void>();
const notify = () => listeners.forEach((l) => l());

/** Subscribe to changes (install prompt ready, update waiting). Returns the unsubscribe function. */
export function onPwaChange(fn: () => void): () => void {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

// The browser offers its install prompt once, early: keep it for when it's useful (after a first referral).
if (typeof window !== 'undefined') {
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferred = e as InstallPrompt;
        notify();
    });
    window.addEventListener('appinstalled', () => {
        deferred = null;
        notify();
    });
}

/** Running from the home screen (or as an installed desktop app). */
export const standalone = () =>
    typeof window !== 'undefined' &&
    (window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true);

/** iPhone or iPad (iPadOS reports itself as a Mac with a touch screen). */
export const ios = () =>
    typeof navigator !== 'undefined' &&
    (/iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1));

export const canPromptInstall = () => deferred !== null;

/** Show the browser's own install prompt; true when the person installed. */
export async function promptInstall(): Promise<boolean> {
    if (!deferred) return false;
    const e = deferred;
    deferred = null;
    notify();
    await e.prompt();
    return (await e.userChoice).outcome === 'accepted';
}

export const updateWaiting = () => waiting !== null;

/** Let the new version take over, then reload once it has. */
export function applyUpdate() {
    if (!waiting) return;
    navigator.serviceWorker.addEventListener('controllerchange', () => window.location.reload(), { once: true });
    waiting.postMessage('skip-waiting');
}

let registered = false;

/** Register the service worker for this build. A new build installs a new copy, which waits until applyUpdate(). */
export async function registerServiceWorker(version: string | null | undefined): Promise<void> {
    if (registered || typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return;
    registered = true;
    try {
        const reg = await navigator.serviceWorker.register(`/sw.js?v=${encodeURIComponent(version || 'dev')}`, { scope: '/' });
        const track = (sw: ServiceWorker | null) => {
            if (!sw) return;
            sw.addEventListener('statechange', () => {
                // Only an update (a copy already in charge) needs asking; the very first install just takes over.
                if (sw.state === 'installed' && navigator.serviceWorker.controller) {
                    waiting = sw;
                    notify();
                }
            });
        };
        if (reg.waiting && navigator.serviceWorker.controller) {
            waiting = reg.waiting;
            notify();
        }
        track(reg.installing);
        reg.addEventListener('updatefound', () => track(reg.installing));
    } catch {
        // No service worker (private mode, old browser): the site works as before, just not offline.
    }
}

/** Signed out: forget every member page kept on this device. */
export function forgetKeptPages() {
    if (typeof navigator !== 'undefined' && navigator.serviceWorker?.controller) navigator.serviceWorker.controller.postMessage('forget-pages');
}
