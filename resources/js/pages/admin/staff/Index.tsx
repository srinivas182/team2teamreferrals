import { router, useForm } from '@inertiajs/react';
import { ShieldAlert, ShieldCheck, UserPlus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Field, Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Staff = {
    id: string;
    name: string;
    email: string;
    superAdmin: boolean;
    permissions: string[];
    twoFactor: boolean;
    invited: boolean;
    lastLogin: string | null;
    active: boolean;
};
type Catalogue = Record<string, Record<string, string>>;

function PermissionPicker({ catalogue, value, onChange }: { catalogue: Catalogue; value: string[]; onChange: (v: string[]) => void }) {
    const toggle = (p: string) => onChange(value.includes(p) ? value.filter((x) => x !== p) : [...value, p]);
    return (
        <div className="space-y-4">
            {Object.entries(catalogue).map(([area, perms]) => (
                <fieldset key={area}>
                    <legend className="mb-2 text-sm font-semibold">{area}</legend>
                    <div className="space-y-2">
                        {Object.entries(perms).map(([key, label]) => (
                            <label key={key} className="flex items-start gap-2 text-sm">
                                <input type="checkbox" className="mt-0.5" checked={value.includes(key)} onChange={() => toggle(key)} />
                                {label}
                            </label>
                        ))}
                    </div>
                </fieldset>
            ))}
        </div>
    );
}

export default function StaffIndex({ staff, catalogue, status, seo }: { staff: Staff[]; catalogue: Catalogue; status?: string; seo: Seo }) {
    const [inviting, setInviting] = useState(false);
    const [editing, setEditing] = useState<Staff | null>(null);
    const invite = useForm({ first_name: '', last_name: '', email: '', permissions: [] as string[] });
    const edit = useForm({ permissions: [] as string[] });

    const sendInvite = (e: FormEvent) => {
        e.preventDefault();
        invite.post('/admin/staff', { preserveScroll: true, onSuccess: () => (setInviting(false), invite.reset()) });
    };

    return (
        <AdminLayout
            title="Staff"
            actions={
                <Button onClick={() => setInviting(true)}>
                    <UserPlus /> Invite staff
                </Button>
            }
        >
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-5">
                    {statusMessage(status)}
                </Alert>
            )}
            <Card className="overflow-x-auto">
                <table className="w-full min-w-[760px] text-sm">
                    <thead className="text-left text-muted">
                        <tr>
                            {['Person', 'Access', 'Two-step', 'Last sign-in', ''].map((h, i) => (
                                <th key={i} scope="col" className="px-4 py-3 font-medium">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {staff.map((s) => (
                            <tr key={s.id} className="border-t border-line">
                                <td className="px-4 py-3">
                                    <div className="font-medium">
                                        {s.name} {!s.active && <Badge tone="danger">Deactivated</Badge>}
                                    </div>
                                    <div className="text-muted">{s.email}</div>
                                </td>
                                <td className="px-4 py-3">
                                    {s.superAdmin ? (
                                        <Badge tone="sender">Super admin · everything</Badge>
                                    ) : (
                                        <span className="text-muted">{s.permissions.length} permissions</span>
                                    )}
                                </td>
                                <td className="px-4 py-3">
                                    {s.twoFactor ? (
                                        <Badge tone="success">
                                            <ShieldCheck className="size-3" aria-hidden /> On
                                        </Badge>
                                    ) : (
                                        <Badge tone="warning">
                                            <ShieldAlert className="size-3" aria-hidden /> Not set up
                                        </Badge>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-muted">{s.invited ? 'Invited, not signed in yet' : s.lastLogin}</td>
                                <td className="px-4 py-3 text-right whitespace-nowrap">
                                    {!s.superAdmin && (
                                        <>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => {
                                                    edit.setData('permissions', s.permissions);
                                                    setEditing(s);
                                                }}
                                            >
                                                Permissions
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className={s.active ? 'text-danger' : ''}
                                                onClick={() => router.patch(`/admin/staff/${s.id}/active`, {}, { preserveScroll: true })}
                                            >
                                                {s.active ? 'Deactivate' : 'Reactivate'}
                                            </Button>
                                        </>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>

            <Dialog open={inviting} onOpenChange={setInviting}>
                <DialogContent
                    title="Invite staff"
                    description="They’ll get an email to set a password, then must turn on two-step sign-in before opening the admin."
                >
                    <form onSubmit={sendInvite} className="space-y-4" noValidate>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="First name" error={invite.errors.first_name}>
                                <Input value={invite.data.first_name} onChange={(e) => invite.setData('first_name', e.target.value)} />
                            </Field>
                            <Field label="Last name" error={invite.errors.last_name}>
                                <Input value={invite.data.last_name} onChange={(e) => invite.setData('last_name', e.target.value)} />
                            </Field>
                        </div>
                        <Field label="Work email" error={invite.errors.email}>
                            <Input type="email" value={invite.data.email} onChange={(e) => invite.setData('email', e.target.value)} />
                        </Field>
                        <PermissionPicker catalogue={catalogue} value={invite.data.permissions} onChange={(v) => invite.setData('permissions', v)} />
                        <div className="flex justify-end gap-2 border-t border-line pt-4">
                            <Button type="button" variant="ghost" onClick={() => setInviting(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" loading={invite.processing}>
                                Send invitation
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent title={`Permissions for ${editing?.name ?? ''}`} description="Changes apply the next time they open a page.">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (editing) edit.put(`/admin/staff/${editing.id}`, { preserveScroll: true, onSuccess: () => setEditing(null) });
                        }}
                        className="space-y-4"
                    >
                        <PermissionPicker catalogue={catalogue} value={edit.data.permissions} onChange={(v) => edit.setData('permissions', v)} />
                        <div className="flex justify-end gap-2 border-t border-line pt-4">
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
                                Cancel
                            </Button>
                            <Button type="submit" loading={edit.processing}>
                                Save permissions
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
