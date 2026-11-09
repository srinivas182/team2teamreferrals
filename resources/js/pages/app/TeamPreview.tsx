import { Link, usePage } from '@inertiajs/react';
import { BadgeCheck, Globe, MapPin, Phone, Users } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Props = {
    team: {
        name: string;
        bio: string | null;
        serviceArea: string | null;
        website: string | null;
        phone: string | null;
        social: Record<string, string>;
        foundedYear: number | null;
        logo: string | null;
        members: number;
    };
    licenses: string[];
    seats: { group: string; territory: string; founding: boolean }[];
    strength: { score: number };
    company: { name: string; cities: number } | null;
    seo: Seo;
};

/** How the team will look to other pros and clients. Publishing arrives with public profiles (Sprint 26). */
export default function TeamPreview({ team, licenses, seats, strength, company, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <PortalLayout title="Profile preview" user={{ name: auth.user?.name ?? '', team: team.name }}>
            <SeoHead seo={seo} />
            <Alert tone="info" className="mb-4 max-w-3xl">
                Preview only: your public profile goes live with public profiles. Profile strength {strength.score}/100.{' '}
                <Link href="/app/team" className="font-medium underline">
                    Improve it
                </Link>
            </Alert>
            <Card className="max-w-3xl overflow-hidden">
                <div className="h-24" style={{ background: 'linear-gradient(120deg,#2451F5,#FF7A1A)' }} />
                <div className="-mt-10 px-6 pb-6">
                    {team.logo ? (
                        <img src={team.logo} alt="" className="size-20 rounded-xl object-cover ring-4 ring-surface" />
                    ) : (
                        <div className="flex size-20 items-center justify-center rounded-xl bg-bg text-2xl font-bold ring-4 ring-surface">
                            {team.name[0]}
                        </div>
                    )}
                    <h1 className="mt-3 font-display text-2xl font-extrabold">{team.name}</h1>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {licenses.map((s) => (
                            <Badge key={s} tone="success">
                                <BadgeCheck className="size-3" aria-hidden /> {s} license verified
                            </Badge>
                        ))}
                        {seats.some((s) => s.founding) && <Badge tone="sender">Founding member</Badge>}
                        {company && (
                            <Badge tone="info">
                                Part of {company.name}, {company.cities} {company.cities === 1 ? 'city' : 'cities'}
                            </Badge>
                        )}
                    </div>
                    {team.bio && <p className="mt-4 text-muted">{team.bio}</p>}
                    <ul className="mt-4 space-y-1.5 text-sm">
                        {team.serviceArea && (
                            <li className="flex gap-2">
                                <MapPin className="size-4 text-muted" aria-hidden /> {team.serviceArea}
                            </li>
                        )}
                        {team.website && (
                            <li className="flex gap-2">
                                <Globe className="size-4 text-muted" aria-hidden /> {team.website}
                            </li>
                        )}
                        {team.phone && (
                            <li className="flex gap-2">
                                <Phone className="size-4 text-muted" aria-hidden /> {team.phone}
                            </li>
                        )}
                        <li className="flex gap-2">
                            <Users className="size-4 text-muted" aria-hidden /> {team.members} on the team
                            {team.foundedYear ? ` · since ${team.foundedYear}` : ''}
                        </li>
                    </ul>
                    {seats.length > 0 && (
                        <div className="mt-5 border-t border-line pt-4">
                            <h2 className="text-sm font-semibold">Seats</h2>
                            <ul className="mt-2 space-y-1 text-sm">
                                {seats.map((s) => (
                                    <li key={s.group + s.territory}>
                                        {s.territory} · <span className="text-muted">{s.group}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </Card>
        </PortalLayout>
    );
}
