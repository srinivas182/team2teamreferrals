import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { axe } from 'vitest-axe';
import { Badge, TempBadge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CommandPalette } from '@/components/ui/command';
import { Countdown, Kpi, Segmented } from '@/components/ui/data';
import { Field, Input, Select } from '@/components/ui/field';
import { Avatar, TrustRing } from '@/components/ui/people';

describe('Button', () => {
    it('disables itself and reports busy while loading', async () => {
        const { container } = render(<Button loading>Send referral</Button>);
        const btn = screen.getByRole('button', { name: 'Send referral' });
        expect(btn).toHaveProperty('disabled', true);
        expect(btn.getAttribute('aria-busy')).toBe('true');
        expect(await axe(container)).toHaveNoViolations();
    });

    it('renders its child with button styles when asChild is set', () => {
        render(
            <Button asChild>
                <a href="/register">Claim your city</a>
            </Button>,
        );
        expect(screen.getByRole('link', { name: 'Claim your city' }).className).toContain('bg-primary');
    });
});

describe('Field', () => {
    it('links label, control and error for screen readers', async () => {
        const { container } = render(
            <Field label="Mobile" error="Enter a valid US mobile number">
                <Input defaultValue="555" />
            </Field>,
        );
        const input = screen.getByLabelText('Mobile');
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(screen.getByText('Enter a valid US mobile number').id).toBe(input.getAttribute('aria-describedby'));
        expect(await axe(container)).toHaveNoViolations();
    });

    it('shows hints when there is no error', async () => {
        const { container } = render(
            <Field label="Group" hint="You can join more groups later.">
                <Select>
                    <option>Sun Belt Top Teams</option>
                </Select>
            </Field>,
        );
        expect(screen.getByLabelText('Group').getAttribute('aria-invalid')).toBeNull();
        expect(await axe(container)).toHaveNoViolations();
    });
});

describe('Status and data', () => {
    it('never conveys temperature or status by colour alone', async () => {
        const { container } = render(
            <div>
                <TempBadge t="hot" />
                <TempBadge t="warm" />
                <TempBadge t="future" />
                <Badge tone="danger">Deactivated</Badge>
            </div>,
        );
        for (const label of ['Hot', 'Warm', 'Future', 'Deactivated']) expect(screen.getByText(label)).toBeTruthy();
        expect(await axe(container)).toHaveNoViolations();
    });

    it('labels trust scores, including new members', async () => {
        const { container } = render(
            <div>
                <TrustRing score={94} />
                <TrustRing score="new" />
                <Avatar name="Chris Delgado" />
            </div>,
        );
        expect(screen.getByRole('img', { name: 'Trust score 94 out of 100' })).toBeTruthy();
        expect(screen.getByRole('img', { name: 'New member, no trust score yet' })).toBeTruthy();
        expect(await axe(container)).toHaveNoViolations();
    });

    it('shows countdown urgency and overdue state', () => {
        render(
            <div>
                <Countdown minutesLeft={134} total={1440} />
                <Countdown minutesLeft={0} overdue />
            </div>,
        );
        expect(screen.getByText('2h 14m left')).toBeTruthy();
        expect(screen.getByText('Overdue')).toBeTruthy();
    });

    it('treats a fall in a lower-is-better KPI as good news', () => {
        render(<Kpi label="Median time to accept" value="19 min" change={-8} lowerIsBetter />);
        expect(screen.getByText('-8%', { exact: false }).closest('div')?.className).toContain('text-success');
    });
});

describe('Segmented', () => {
    function Demo() {
        const [v, setV] = useState<'board' | 'list'>('board');
        return (
            <Segmented
                label="View"
                value={v}
                onChange={setV}
                options={[
                    ['board', 'Board'],
                    ['list', 'List'],
                ]}
            />
        );
    }

    it('works with arrow keys like a radio group', async () => {
        const { container } = render(<Demo />);
        const board = screen.getByRole('radio', { name: 'Board' });
        board.focus();
        await userEvent.keyboard('{ArrowRight}');
        expect(screen.getByRole('radio', { name: 'List' }).getAttribute('aria-checked')).toBe('true');
        expect(await axe(container)).toHaveNoViolations();
    });
});

describe('CommandPalette', () => {
    it('filters items as you type and runs the chosen one', async () => {
        let ran = '';
        render(
            <CommandPalette
                open
                onOpenChange={() => {}}
                items={[
                    { group: 'Actions', label: 'Send a referral', onSelect: () => (ran = 'send') },
                    { group: 'Go to', label: 'Pipeline', onSelect: () => (ran = 'pipeline') },
                ]}
            />,
        );
        await userEvent.type(screen.getByPlaceholderText('Search or jump to…'), 'pipe');
        expect(screen.queryByText('Send a referral')).toBeNull();
        await userEvent.keyboard('{Enter}');
        expect(ran).toBe('pipeline');
    });
});

describe('AnswerInput', () => {
    it('is labelled by its question inside a Field, for every kind of answer', async () => {
        const { AnswerInput } = await import('@/components/referrals/AnswerInput');
        const kinds = [
            { key: 'a', label: 'System size', type: 'text' },
            { key: 'b', label: 'Roof type', type: 'choice', options: ['Tile', 'Metal'] },
            { key: 'c', label: 'Anything else', type: 'textarea' },
            { key: 'd', label: 'Owns the home', type: 'boolean' },
        ];
        const { container } = render(
            <>
                {kinds.map((f) => (
                    <Field key={f.key} label={f.label}>
                        <AnswerInput f={f} value={undefined} onChange={() => undefined} />
                    </Field>
                ))}
            </>,
        );
        for (const f of kinds) expect(screen.getByLabelText(f.label)).toBeTruthy();
        expect(await axe(container)).toHaveNoViolations();
    });
});
