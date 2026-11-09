import { Monitor, Moon, Sun } from 'lucide-react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useTheme, type ThemeChoice } from '@/hooks/use-theme';

const options: [ThemeChoice, string, typeof Sun][] = [
    ['light', 'Light', Sun],
    ['dark', 'Dark', Moon],
    ['system', 'Match device', Monitor],
];

export function ThemeToggle() {
    const { choice, resolved, set } = useTheme();
    const Icon = resolved === 'dark' ? Moon : Sun;
    return (
        <DropdownMenu>
            <DropdownMenuTrigger className="rounded-md p-2 text-muted hover:bg-bg hover:text-ink" aria-label={`Theme: ${choice}`}>
                <Icon className="size-5" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {options.map(([v, label, I]) => (
                    <DropdownMenuItem key={v} onSelect={() => set(v)} aria-checked={choice === v}>
                        <I className="size-4" />
                        {label}
                        {choice === v && <span className="ml-auto text-xs text-muted">On</span>}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
