import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Trash2, UserPlus } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Props = {
    specialties?: string[];
    languages?: string[];
    specialtyOptions?: string[];
    languageOptions?: string[];
    showDealValue?: boolean;
    quickReplies?: string[];
    sharing?: { mode: 'everyone' | 'turns' | 'leader'; minutes: number | null };
    team: {
        id: string;
        name: string;
        bio: string | null;
        website: string | null;
        phone: string | null;
        social: Record<string, string>;
        serviceArea: string | null;
        foundedYear: number | null;
        logo: string | null;
    };
    role: string;
    canEdit: boolean;
    canManage: boolean;
    members: { id: string; name: string; email: string; role: string; trained: boolean }[];
    teamTrained: boolean;
    invites: { id: number; email: string; role: string; expires: string }[];
    roles: Record<string, string>;
    status?: string;
    seo: Seo;
};

const socials = ['linkedin', 'facebook', 'instagram', 'youtube', 'x'] as const;

export default function Team({
    specialties = [],
    languages = [],
    specialtyOptions = [],
    languageOptions = [],
    showDealValue = false,
    quickReplies = [],
    sharing = { mode: 'everyone', minutes: null },
    team,
    canEdit,
    canManage,
    members,
    teamTrained,
    invites,
    roles,
    status,
    seo,
}: Props) {
    const { auth } = usePage<SharedProps>().props;
    const form = useForm<{
        name: string;
        bio: string;
        website: string;
        phone: string;
        service_area: string;
        founded_year: string;
        social: Record<string, string>;
        logo: File | null;
    }>({
        name: team.name,
        bio: team.bio ?? '',
        website: team.website ?? '',
        phone: team.phone ?? '',
        service_area: team.serviceArea ?? '',
        founded_year: team.foundedYear ? String(team.foundedYear) : '',
        social: { ...team.social },
        logo: null,
    });
    const invite = useForm({ email: '', role: 'member' });
    const e = form.errors as Record<string, string>;
    return (
        <PortalLayout title="Team" user={{ name: auth.user?.name ?? '', team: team.name }}>
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4 max-w-5xl">
                    {statusMessage(status)}
                </Alert>
            )}
            <div className="grid max-w-5xl gap-6 lg:grid-cols-[1.3fr_1fr]">
                <Card className="space-y-4 p-6">
                    <div className="flex items-center gap-3">
                        <h2 className="font-semibold">Team profile</h2>
                        <a href="/app/team/preview" className="ml-auto text-sm font-medium text-primary">
                            Preview your public profile
                        </a>
                    </div>
                    <fieldset disabled={!canEdit} className="space-y-4">
                        <div className="flex items-center gap-4">
                            {team.logo ? (
                                <img src={team.logo} alt="" className="size-16 rounded-lg object-cover" />
                            ) : (
                                <div className="flex size-16 items-center justify-center rounded-lg bg-bg text-xs text-muted">Logo</div>
                            )}
                            <Field label="Logo" hint="Square, JPG, PNG or WebP, up to 2 MB." error={e.logo}>
                                <input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    onChange={(ev) => form.setData('logo', ev.target.files?.[0] ?? null)}
                                />
                            </Field>
                        </div>
                        <Field label="Team name" error={e.name}>
                            <Input value={form.data.name} onChange={(ev) => form.setData('name', ev.target.value)} />
                        </Field>
                        <Field label="About your team" hint="What clients and other pros should know. Shown on your public profile." error={e.bio}>
                            <Textarea rows={4} value={form.data.bio} onChange={(ev) => form.setData('bio', ev.target.value)} />
                        </Field>
                        <Field label="Areas you serve" hint="In plain words, e.g. “Austin and Travis County”." error={e.service_area}>
                            <Input value={form.data.service_area} onChange={(ev) => form.setData('service_area', ev.target.value)} />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field label="Website" error={e.website}>
                                <Input type="url" value={form.data.website} onChange={(ev) => form.setData('website', ev.target.value)} />
                            </Field>
                            <Field label="Phone" error={e.phone}>
                                <Input value={form.data.phone} onChange={(ev) => form.setData('phone', ev.target.value)} />
                            </Field>
                            <Field label="Founded" error={e.founded_year}>
                                <Input
                                    type="number"
                                    value={form.data.founded_year}
                                    onChange={(ev) => form.setData('founded_year', ev.target.value)}
                                />
                            </Field>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {socials.map((k) => (
                                <Field key={k} label={k === 'x' ? 'X' : k[0]!.toUpperCase() + k.slice(1)} error={e[`social.${k}`]}>
                                    <Input
                                        type="url"
                                        placeholder="https://"
                                        value={form.data.social[k] ?? ''}
                                        onChange={(ev) => form.setData('social', { ...form.data.social, [k]: ev.target.value })}
                                    />
                                </Field>
                            ))}
                        </div>
                        {canEdit && (
                            <Button loading={form.processing} onClick={() => form.post('/app/team', { forceFormData: true, preserveScroll: true })}>
                                Save profile
                            </Button>
                        )}
                    </fieldset>
                    {!canEdit && <p className="text-sm text-muted">Only the team leader and assistants can edit the profile.</p>}
                </Card>
                <div className="space-y-6">
                    <Card className="p-6">
                        <div className="flex items-center gap-2">
                            <h2 className="font-semibold">Members</h2>
                            {canEdit && members.length > 1 && (
                                <Link href="/app/team/performance" className="text-sm font-medium text-primary hover:underline">
                                    See how each member is doing
                                </Link>
                            )}
                            {teamTrained && (
                                <Badge tone="success" className="ml-auto">
                                    Network trained
                                </Badge>
                            )}
                        </div>
                        <ul className="mt-3 divide-y divide-line">
                            {members.map((m) => (
                                <li key={m.id} className="flex items-center gap-3 py-3 text-sm">
                                    <div className="min-w-0 flex-1">
                                        <div className="font-medium">{m.name}</div>
                                        <div className="truncate text-muted">{m.email}</div>
                                    </div>
                                    <Badge tone={m.role === 'leader' ? 'sender' : 'neutral'}>{roles[m.role]}</Badge>
                                    {m.trained ? (
                                        <Badge tone="success">Trained</Badge>
                                    ) : canManage ? (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => router.post(`/app/team/members/${m.id}/remind-training`, {}, { preserveScroll: true })}
                                        >
                                            Remind
                                        </Button>
                                    ) : (
                                        <Badge tone="warning">Training to do</Badge>
                                    )}
                                    {canManage && m.role !== 'leader' && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Remove ${m.name}`}
                                            onClick={() => router.delete(`/app/team/members/${m.id}`, { preserveScroll: true })}
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                    {canManage && (
                        <Card className="space-y-3 p-6">
                            <h2 className="font-semibold">Invite to your team</h2>
                            <Field label="Email" error={invite.errors.email}>
                                <Input type="email" value={invite.data.email} onChange={(ev) => invite.setData('email', ev.target.value)} />
                            </Field>
                            <Field label="Role" hint="Assistants can edit the profile and help with referrals; members work referrals.">
                                <Select value={invite.data.role} onChange={(ev) => invite.setData('role', ev.target.value)}>
                                    <option value="member">Member</option>
                                    <option value="assistant">Assistant</option>
                                </Select>
                            </Field>
                            <Button
                                variant="secondary"
                                loading={invite.processing}
                                onClick={() => invite.post('/app/team/invites', { preserveScroll: true, onSuccess: () => invite.reset('email') })}
                            >
                                <UserPlus /> Send invitation
                            </Button>
                            {invites.length > 0 && (
                                <ul className="mt-2 space-y-2 border-t border-line pt-3 text-sm">
                                    {invites.map((i) => (
                                        <li key={i.id} className="flex items-center gap-2">
                                            <span className="flex-1 truncate">{i.email}</span>
                                            <Badge>{roles[i.role]}</Badge>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => router.delete(`/app/team/invites/${i.id}`, { preserveScroll: true })}
                                            >
                                                Cancel
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    )}
                </div>
                {canEdit && (
                    <Card className="mt-6 max-w-5xl space-y-3 p-6">
                        <h2 className="font-semibold">Specialties and languages</h2>
                        <p className="text-sm text-muted">Shown on your profile and in the member directory.</p>
                        {(
                            [
                                ['specialties', specialtyOptions, specialties],
                                ['languages', languageOptions, languages],
                            ] as [string, string[], string[]][]
                        ).map(([key, opts, chosen]) => (
                            <div key={key} className="flex flex-wrap gap-1.5" role="group" aria-label={key}>
                                {opts.map((o) => (
                                    <button
                                        key={o}
                                        aria-pressed={chosen.includes(o)}
                                        className={`h-8 rounded-full border px-3 text-xs ${chosen.includes(o) ? 'border-primary bg-primary/10 text-primary' : 'border-line'}`}
                                        onClick={() =>
                                            router.post(
                                                '/app/team',
                                                { name: team.name, [key]: chosen.includes(o) ? chosen.filter((c) => c !== o) : [...chosen, o] },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {o}
                                    </button>
                                ))}
                            </div>
                        ))}
                    </Card>
                )}
                {canEdit && (
                    <Card className="mt-6 max-w-5xl p-6">
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={showDealValue}
                                onChange={(e) =>
                                    router.post('/app/team', { name: team.name, show_deal_value: e.target.checked }, { preserveScroll: true })
                                }
                            />{' '}
                            Show the total value of our closed referrals on our profile
                        </label>
                    </Card>
                )}
                <LeadSharingCard sharing={sharing} canManage={canManage} />
                {canEdit && (
                    <Card id="quick-replies" className="mt-6 max-w-5xl space-y-3 p-6">
                        <h2 className="font-semibold">Quick replies</h2>
                        <p className="text-sm text-muted">One-tap phrases for referral messages, one per line (up to 10).</p>
                        <Textarea rows={5} defaultValue={quickReplies.join('\n')} id="qr" aria-label="Quick replies" />
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    '/app/team/quick-replies',
                                    { replies: (document.getElementById('qr') as HTMLTextAreaElement).value.split('\n') },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Save quick replies
                        </Button>
                    </Card>
                )}
            </div>
        </PortalLayout>
    );
}

const SHARING = [
    {
        mode: 'everyone',
        title: 'Open to everyone (first to accept)',
        tip: 'Everyone in the team is told about each new referral. Whoever accepts first gets it. If nobody has accepted in the time below, you are told and can accept it or assign it.',
    },
    {
        mode: 'turns',
        title: 'Take turns (round robin)',
        tip: 'Each new referral goes to one person at a time, in order: the next referral starts with the next person. They have the time below to accept, then it moves on. After everyone has had a turn it is open to the whole team and you are told.',
    },
    {
        mode: 'leader',
        title: 'Leader assigns',
        tip: 'Only you (and assistants) are told about new referrals. You accept each one or assign it to a teammate. Members never see referrals you haven’t given them.',
    },
] as const;

const WINDOWS: [number, string][] = [
    [15, '15 minutes'],
    [30, '30 minutes'],
    [60, '1 hour'],
    [120, '2 hours'],
    [180, '3 hours'],
    [240, '4 hours'],
    [360, '6 hours'],
    [480, '8 hours'],
    [720, '12 hours'],
];

/** How new referrals are shared in the team (one mode at a time), and how long members have to accept. Leader only edits. */
function LeadSharingCard({ sharing, canManage }: { sharing: { mode: string; minutes: number | null }; canManage: boolean }) {
    const form = useForm({ mode: sharing.mode, minutes: sharing.minutes as number | null });
    return (
        <Card id="sharing" className="mt-6 max-w-5xl space-y-4 p-6">
            <div>
                <h2 className="font-semibold">How new referrals are shared</h2>
                <p className="text-sm text-muted">
                    One way at a time. {canManage ? 'Only you, the leader, can change it.' : 'Your team leader sets this.'}
                </p>
            </div>
            <fieldset className="grid gap-3" disabled={!canManage}>
                <legend className="sr-only">How new referrals are shared</legend>
                {SHARING.map((s) => (
                    <label
                        key={s.mode}
                        className={cn(
                            'flex cursor-pointer items-start gap-3 rounded-lg border p-4 text-sm',
                            form.data.mode === s.mode ? 'border-primary bg-cobalt-50/60 dark:bg-cobalt-500/10' : 'border-line',
                        )}
                    >
                        <input
                            type="radio"
                            name="sharing"
                            className="mt-1"
                            checked={form.data.mode === s.mode}
                            onChange={() => form.setData('mode', s.mode)}
                        />
                        <span>
                            <span className="block font-semibold">{s.title}</span>
                            <span className="mt-1 block text-muted">{s.tip}</span>
                        </span>
                    </label>
                ))}
            </fieldset>
            {form.data.mode !== 'leader' && (
                <Field label={form.data.mode === 'turns' ? 'Time each person has on their turn' : 'Time members have to accept before you’re told'}>
                    <Select
                        value={form.data.minutes ?? ''}
                        disabled={!canManage}
                        onChange={(e) => form.setData('minutes', e.target.value ? Number(e.target.value) : null)}
                    >
                        <option value="">Default: ¼ of the network’s response time</option>
                        {WINDOWS.map(([m, label]) => (
                            <option key={m} value={m}>
                                {label}
                            </option>
                        ))}
                    </Select>
                </Field>
            )}
            {canManage && (
                <Button onClick={() => form.post('/app/team/sharing', { preserveScroll: true })} loading={form.processing}>
                    Save
                </Button>
            )}
        </Card>
    );
}
