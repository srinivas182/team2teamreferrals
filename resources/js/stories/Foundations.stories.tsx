import type { Meta, StoryObj } from '@storybook/react-vite';
import { Plus, Send } from 'lucide-react';
import { useState } from 'react';
import { Logo } from '@/components/brand/Logo';
import { Badge, TempBadge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Countdown, Kpi, Segmented } from '@/components/ui/data';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { Avatar, TrustRing } from '@/components/ui/people';

const meta: Meta = { title: 'Foundations' };
export default meta;
type Story = StoryObj;

export const Brand: Story = {
    render: () => (
        <div className="flex flex-wrap items-center gap-8">
            <Logo className="h-10 w-[170px]" />
            <Logo markOnly className="h-10 w-12" />
        </div>
    ),
};

export const Buttons: Story = {
    render: () => (
        <div className="flex flex-wrap items-center gap-3">
            <Button>Primary</Button>
            <Button variant="accent">Accent</Button>
            <Button variant="secondary">Secondary</Button>
            <Button variant="ghost">Ghost</Button>
            <Button variant="danger">Danger</Button>
            <Button variant="link">Link</Button>
            <Button disabled>Disabled</Button>
            <Button loading>Sending…</Button>
            <Button size="sm">
                <Plus /> Small
            </Button>
            <Button size="lg">
                <Send /> Large
            </Button>
        </div>
    ),
};

export const Forms: Story = {
    render: () => (
        <div className="grid max-w-3xl gap-4 sm:grid-cols-2">
            <Field label="Team name" hint="Shown on your public profile.">
                <Input defaultValue="Red Rock Realty Group" />
            </Field>
            <Field label="Mobile" error="Enter a valid US mobile number">
                <Input defaultValue="555" />
            </Field>
            <Field label="Group">
                <Select>
                    <option>Mountain West Alliance</option>
                </Select>
            </Field>
            <Field label="Disabled">
                <Input disabled defaultValue="Read only" />
            </Field>
            <Field label="About your team" className="sm:col-span-2">
                <Textarea defaultValue="Southern Utah's top buyer team." />
            </Field>
        </div>
    ),
};

export const StatusAndData: Story = {
    render: () => (
        <div className="space-y-6">
            <div className="flex flex-wrap gap-2">
                <Badge>Neutral</Badge>
                <Badge tone="success">Active</Badge>
                <Badge tone="warning">Warned</Badge>
                <Badge tone="danger">Deactivated</Badge>
                <Badge tone="info">Contacted</Badge>
                <Badge tone="sender">Sender</Badge>
                <Badge tone="receiver">Receiver</Badge>
                <TempBadge t="hot" />
                <TempBadge t="warm" />
                <TempBadge t="future" />
            </div>
            <div className="flex flex-wrap gap-2">
                <Countdown minutesLeft={1200} total={1440} />
                <Countdown minutesLeft={600} total={1440} />
                <Countdown minutesLeft={60} total={1440} />
                <Countdown minutesLeft={0} overdue />
            </div>
            <div className="flex items-center gap-4">
                <Avatar name="Jordan Hale" />
                <Avatar name="Chris Delgado" tone="receiver" />
                <TrustRing score={94} />
                <TrustRing score={71} />
                <TrustRing score="new" />
            </div>
            <div className="grid max-w-3xl gap-4 sm:grid-cols-2">
                <Kpi label="Referral fees this year" value="$19,920" change={18} spark={[2, 3, 2, 5, 4, 6]} />
                <Kpi label="Median time to accept" value="19 min" change={-8} lowerIsBetter />
            </div>
        </div>
    ),
};

function SegmentedDemo() {
    const [v, setV] = useState<'board' | 'list'>('board');
    return (
        <Card className="inline-block p-4">
            <Segmented
                label="View"
                value={v}
                onChange={setV}
                options={[
                    ['board', 'Board'],
                    ['list', 'List'],
                ]}
            />
        </Card>
    );
}
export const SegmentedControl: Story = { render: () => <SegmentedDemo /> };
