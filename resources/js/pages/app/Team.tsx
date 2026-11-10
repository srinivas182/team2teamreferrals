import { router, useForm, usePage } from '@inertiajs/react';
import { Trash2, UserPlus } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Props = {
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

export default function Team({ team, canEdit, canManage, members, teamTrained, invites, roles, status, seo }: Props) {
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
            </div>
        </PortalLayout>
    );
}
