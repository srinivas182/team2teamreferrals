import type { Meta, StoryObj } from '@storybook/react-vite';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { CommandPalette } from '@/components/ui/command';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Field, Textarea } from '@/components/ui/field';
import { Toaster, toast } from '@/components/ui/toaster';
import { Tooltip, TooltipProvider } from '@/components/ui/tooltip';

const meta: Meta = { title: 'Overlays' };
export default meta;
type Story = StoryObj;

export const RejectDialog: Story = {
    render: () => (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="secondary">Reject referral</Button>
            </DialogTrigger>
            <DialogContent title="Reject referral #4822?" description="It goes to the backup team for this city. You won't be charged.">
                <Field label="Note to the sender">
                    <Textarea placeholder="Optional, but it helps them place the lead" />
                </Field>
                <div className="mt-5 flex justify-end gap-2">
                    <DialogClose asChild>
                        <Button variant="ghost">Cancel</Button>
                    </DialogClose>
                    <Button variant="danger">Reject referral</Button>
                </div>
            </DialogContent>
        </Dialog>
    ),
};

export const MenuAndTooltip: Story = {
    render: () => (
        <TooltipProvider>
            <div className="flex gap-3">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="secondary">Actions</Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent>
                        <DropdownMenuItem>Move to stage</DropdownMenuItem>
                        <DropdownMenuItem>Assign to team member</DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem className="text-danger">Mark as lost</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <Tooltip label="Share status with your client">
                    <Button variant="secondary">Share</Button>
                </Tooltip>
            </div>
        </TooltipProvider>
    ),
};

export const Toasts: Story = {
    render: () => (
        <>
            <Button onClick={() => toast.success('Referral sent to Houston', { description: 'Chris has 24 hours to accept.' })}>Show toast</Button>
            <Toaster />
        </>
    ),
};

function PaletteDemo() {
    const [open, setOpen] = useState(true);
    const noop = () => {};
    return (
        <>
            <Button onClick={() => setOpen(true)}>Open palette (Ctrl K)</Button>
            <CommandPalette
                open={open}
                onOpenChange={setOpen}
                items={[
                    { group: 'Actions', label: 'Send a referral', shortcut: 'S', onSelect: noop },
                    { group: 'Go to', label: 'Pipeline', onSelect: noop },
                    { group: 'Go to', label: 'Earnings', onSelect: noop },
                    { group: 'Referrals', label: '#4821 Tom & Ana Brennan → Houston', onSelect: noop },
                ]}
            />
        </>
    );
}
export const CommandPaletteStory: Story = { name: 'Command palette', render: () => <PaletteDemo /> };
