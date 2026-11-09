import { Toaster as Sonner } from 'sonner';
import { useTheme } from '@/hooks/use-theme';

export { toast } from 'sonner';

/** App-wide toasts: bottom on phones, top-right on desktop, announced to screen readers. */
export function Toaster() {
    const { resolved } = useTheme();
    return (
        <Sonner
            theme={resolved}
            position="top-right"
            closeButton
            toastOptions={{ classNames: { toast: '!rounded-lg !border !border-line !bg-surface !text-ink !shadow-md', description: '!text-muted' } }}
        />
    );
}
