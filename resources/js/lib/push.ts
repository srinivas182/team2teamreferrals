/** Browser push: register the service worker, ask permission, and send the subscription to the server. */
const urlBase64ToUint8Array = (b64: string) => {
    const pad = '='.repeat((4 - (b64.length % 4)) % 4);
    const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
};

export const pushSupported = () =>
    typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

export async function enablePush(vapidPublicKey: string): Promise<'enabled' | 'denied' | 'unsupported' | 'error'> {
    if (!pushSupported()) return 'unsupported';
    try {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') return 'denied';
        const reg = await navigator.serviceWorker.register('/sw.js');
        const sub =
            (await reg.pushManager.getSubscription()) ??
            (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(vapidPublicKey) }));
        const token =
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ??
            decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
        const res = await fetch('/app/push-subscriptions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
            credentials: 'same-origin',
            body: JSON.stringify(sub.toJSON()),
        });
        return res.ok ? 'enabled' : 'error';
    } catch {
        return 'error';
    }
}
