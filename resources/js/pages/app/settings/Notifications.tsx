import { router, useForm, usePage } from '@inertiajs/react';
import { BellRing, Lock, Moon, Newspaper, Zap } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { enablePush, pushSupported } from '@/lib/push';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type TypeDef = { key: string; group: string; label: string; description: string; channels: string[]; urgent: boolean; locked: boolean };
type Props = {
    types: TypeDef[];
    channels: Record<string, string>;
    matrix: Record<string, Record<string, boolean>>;
    quiet: { start: string | null; end: string | null; timezone: string };
    chat?: { channel: string; phoneVerified: boolean };
    digest: boolean;
    pushEnabled: boolean;
    pushDevices: number;
    vapidPublicKey: string;
    status?: string;
    seo: Seo;
};

export default function NotificationSettings({
    chat,
    types,
    channels,
    matrix,
    quiet,
    digest,
    pushEnabled,
    pushDevices,
    vapidPublicKey,
    status,
    seo,
}: Props) {
    const { auth } = usePage<SharedProps>().props;
    const form = useForm({
        matrix,
        quiet_start: quiet.start ?? '',
        quiet_end: quiet.end ?? '',
        digest,
        timezone: quiet.timezone,
        chat_channel: chat?.channel ?? 'none',
    });
    const [push, setPush] = useState<string | null>(null);
    const groups = Array.from(new Set(types.map((t) => t.group)));
    const cols = Object.keys(channels);
    const toggle = (type: string, ch: string) =>
        form.setData('matrix', { ...form.data.matrix, [type]: { ...form.data.matrix[type], [ch]: !form.data.matrix[type]?.[ch] } });
    const save = () => form.transform((d) => ({ ...d, quiet_start: d.quiet_start || null, quiet_end: d.quiet_end || null }));

    return (
        <PortalLayout title="Notification settings" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-4xl space-y-6">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}

                <Card className="overflow-x-auto">
                    <table className="w-full min-w-[620px] text-sm">
                        <caption className="sr-only">Choose how you hear about each kind of notification</caption>
                        <thead>
                            <tr className="text-left text-muted">
                                <th scope="col" className="px-5 py-3 font-medium">
                                    Notification
                                </th>
                                {cols.map((c) => (
                                    <th key={c} scope="col" className="w-20 px-2 py-3 text-center font-medium">
                                        {channels[c]}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        {groups.map((g) => (
                            <tbody key={g}>
                                <tr>
                                    <th
                                        colSpan={cols.length + 1}
                                        scope="colgroup"
                                        className="bg-bg px-5 py-2 text-left text-xs font-semibold tracking-wide text-subtle uppercase"
                                    >
                                        {g}
                                    </th>
                                </tr>
                                {types
                                    .filter((t) => t.group === g)
                                    .map((t) => (
                                        <tr
                                            key={t.key}
                                            id={t.key.replace('.', '-')}
                                            className="border-t border-line target:bg-cobalt-50 dark:target:bg-cobalt-500/10"
                                        >
                                            <th scope="row" className="px-5 py-3 text-left font-normal">
                                                <div className="flex flex-wrap items-center gap-2 font-medium">
                                                    {t.label}
                                                    {t.urgent && (
                                                        <Badge tone="warning">
                                                            <Zap className="size-3" aria-hidden /> Urgent
                                                        </Badge>
                                                    )}
                                                    {t.locked && (
                                                        <Badge>
                                                            <Lock className="size-3" aria-hidden /> Always on
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="text-muted">{t.description}</div>
                                            </th>
                                            {cols.map((c) => (
                                                <td key={c} className="px-2 py-3 text-center">
                                                    {t.channels.includes(c) ? (
                                                        <input
                                                            type="checkbox"
                                                            className="size-4"
                                                            aria-label={`${t.label} by ${channels[c]}`}
                                                            checked={t.locked || !!form.data.matrix[t.key]?.[c]}
                                                            disabled={t.locked}
                                                            onChange={() => toggle(t.key, c)}
                                                        />
                                                    ) : (
                                                        <span className="text-subtle" aria-label="Not available">
                                                            –
                                                        </span>
                                                    )}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                            </tbody>
                        ))}
                    </table>
                </Card>

                <div className="grid gap-6 md:grid-cols-2">
                    <Card className="p-6">
                        <Moon className="text-primary" aria-hidden />
                        <h2 className="mt-3 font-semibold">Quiet hours</h2>
                        <p className="mt-1 text-sm text-muted">
                            Non-urgent texts, emails and pushes wait until quiet hours end. Urgent items, like a referral waiting for you, still come
                            through. Times are in your time zone, below.
                        </p>
                        <div className="mt-4 grid grid-cols-2 gap-3">
                            <Field
                                label="Referral status updates by chat"
                                hint={
                                    chat?.phoneVerified
                                        ? 'When a referral you sent is accepted or declined. Short names only, never contact details.'
                                        : 'Verify your phone number in your account settings to turn this on.'
                                }
                            >
                                <select
                                    className="h-10 w-full rounded-md border border-line bg-surface px-2"
                                    disabled={!chat?.phoneVerified}
                                    value={form.data.chat_channel}
                                    onChange={(e) => form.setData('chat_channel', e.target.value)}
                                >
                                    <option value="none">Off</option>
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="sms">Text message</option>
                                </select>
                            </Field>
                            <Field
                                label="Your time zone"
                                hint="Used for quiet hours, reminders and event times. It starts from your team’s city."
                                error={form.errors.timezone}
                            >
                                <select
                                    className="h-10 w-full rounded-md border border-line bg-surface px-2"
                                    value={form.data.timezone}
                                    onChange={(e) => form.setData('timezone', e.target.value)}
                                >
                                    {US_ZONES.map(([z, l]) => (
                                        <option key={z} value={z}>
                                            {l}
                                        </option>
                                    ))}
                                    {!US_ZONES.some(([z]) => z === form.data.timezone) && (
                                        <option value={form.data.timezone}>{form.data.timezone}</option>
                                    )}
                                </select>
                            </Field>
                            <Field label="From" error={form.errors.quiet_start}>
                                <Input type="time" value={form.data.quiet_start} onChange={(e) => form.setData('quiet_start', e.target.value)} />
                            </Field>
                            <Field label="Until" error={form.errors.quiet_end}>
                                <Input type="time" value={form.data.quiet_end} onChange={(e) => form.setData('quiet_end', e.target.value)} />
                            </Field>
                        </div>
                    </Card>
                    <Card className="p-6">
                        <Newspaper className="text-primary" aria-hidden />
                        <h2 className="mt-3 font-semibold">Email: as it happens, or a daily summary</h2>
                        <p className="mt-1 text-sm text-muted">
                            Non-urgent updates that arrive close together are always bundled into one email. Or get one summary at 7:00 each morning
                            instead.
                        </p>
                        <label className="mt-4 flex items-center gap-3 text-sm">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={form.data.digest}
                                onChange={(e) => form.setData('digest', e.target.checked)}
                            />
                            Send me one daily summary instead
                        </label>
                    </Card>
                </div>

                {pushEnabled && (
                    <Card className="flex flex-wrap items-center gap-4 p-6">
                        <BellRing className="text-primary" aria-hidden />
                        <div className="min-w-0 flex-1">
                            <h2 className="font-semibold">Push notifications</h2>
                            <p className="text-sm text-muted">
                                {pushDevices > 0
                                    ? `On for ${pushDevices} browser${pushDevices > 1 ? 's' : ''}.`
                                    : 'Get instant alerts on this browser or phone, even when Team to Team isn’t open.'}
                            </p>
                            {push && <p className="mt-1 text-sm text-muted">{push}</p>}
                        </div>
                        {pushDevices > 0 && (
                            <Button variant="ghost" onClick={() => router.delete('/app/push-subscriptions', { preserveScroll: true })}>
                                Turn off everywhere
                            </Button>
                        )}
                        <Button
                            variant="secondary"
                            disabled={!pushSupported()}
                            onClick={async () => {
                                const r = await enablePush(vapidPublicKey);
                                setPush(
                                    {
                                        enabled: 'Push is on for this browser.',
                                        denied: 'Your browser blocked notifications. Allow them in its site settings, then try again.',
                                        unsupported: 'This browser doesn’t support push. On iPhone, add Team to Team to your home screen first.',
                                        error: 'Something went wrong. Please try again.',
                                    }[r],
                                );
                                if (r === 'enabled') router.reload({ only: ['pushDevices'] });
                            }}
                        >
                            Turn on for this browser
                        </Button>
                    </Card>
                )}

                <div>
                    <Button
                        loading={form.processing}
                        onClick={() => {
                            save();
                            form.put('/app/settings/notifications', { preserveScroll: true });
                        }}
                    >
                        Save settings
                    </Button>
                </div>
            </div>
        </PortalLayout>
    );
}

const US_ZONES: [string, string][] = [
    ['America/New_York', 'Eastern'],
    ['America/Chicago', 'Central'],
    ['America/Denver', 'Mountain'],
    ['America/Phoenix', 'Arizona (no daylight saving)'],
    ['America/Los_Angeles', 'Pacific'],
    ['America/Anchorage', 'Alaska'],
    ['Pacific/Honolulu', 'Hawaii'],
    ['America/Puerto_Rico', 'Puerto Rico'],
    ['America/Boise', 'Mountain (Idaho)'],
    ['America/Detroit', 'Eastern (Michigan)'],
    ['America/Indiana/Indianapolis', 'Eastern (Indiana)'],
    ['America/Menominee', 'Central (Michigan Upper Peninsula)'],
];
